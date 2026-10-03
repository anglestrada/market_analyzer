<?php
declare(strict_types=1);

/**
 * Data for the dashboard charts. Each function returns plain arrays that pages
 * embed with json_script() and app.js turns into Chart.js charts.
 */

const CHART_STATUSES = ['article_found', 'no_explanation_found', 'news_search_failed', 'pending'];

/**
 * Downsampled YES price per market over the last $hours.
 * @return array<int, array<int, array{t:string, y:float}>>  market_id => points
 */
function chart_series(array $marketIds, int $hours, int $points): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $marketIds))));
    if (!$ids) {
        return [];
    }
    $hours  = max(1, $hours);
    $bucket = max(300, intdiv($hours * 3600, max(1, $points)));

    $stmt = db()->prepare(
        "SELECT market_id, MAX(captured_at) AS t, (array_agg(yes_price ORDER BY captured_at DESC))[1] AS y
           FROM market_snapshots
          WHERE market_id = ANY (CAST(:ids AS bigint[]))
            AND yes_price IS NOT NULL
            AND captured_at >= NOW() - INTERVAL '$hours hours'
       GROUP BY market_id, FLOOR(EXTRACT(EPOCH FROM captured_at) / $bucket)
       ORDER BY market_id, MIN(captured_at)"
    );
    $stmt->execute([':ids' => '{' . implode(',', $ids) . '}']);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['market_id']][] = ['t' => iso($r['t']), 'y' => (float) $r['y']];
    }
    return $out;
}

/** Shared WHERE for movement charts. Each named parameter is used once (native prepares). */
function movement_filters(DateTimeImmutable $from, DateTimeImmutable $to, array $f = []): array
{
    $where  = ['e.sport = :sport', 'mm.detected_at >= :from', 'mm.detected_at <= :to'];
    $params = [':sport' => DEFAULT_SPORT, ':from' => $from->format('c'), ':to' => $to->format('c')];

    if (!empty($f['side']) && in_array($f['side'], ['yes', 'no'], true)) {
        $where[] = 'mm.dropped_side = :side';
        $params[':side'] = $f['side'];
    }
    if (!empty($f['status'])) {
        $where[] = "COALESCE(ma.explanation_status, 'pending') = :st";
        $params[':st'] = $f['status'];
    }
    if (!empty($f['watch_user_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM watchlist_items w WHERE w.market_id = mm.market_id AND w.user_id = :wu)';
        $params[':wu'] = (int) $f['watch_user_id'];
    }
    return [implode(' AND ', $where), $params];
}

const MOVEMENT_JOINS = 'FROM market_movements mm
    JOIN markets m ON m.id = mm.market_id
    JOIN events  e ON e.id = m.event_id
    LEFT JOIN movement_analysis ma ON ma.movement_id = mm.id';

/**
 * Movements per day (or per month for long ranges), stacked by explanation status.
 * @return array{labels:string[], datasets:array<string,int[]>, totals:array<string,int>, total:int}
 */
function chart_movements_daily(DateTimeImmutable $from, DateTimeImmutable $to, array $f = []): array
{
    $tz      = new DateTimeZone(APP_TIMEZONE);
    $monthly = ($to->getTimestamp() - $from->getTimestamp()) > 92 * 86400;
    $keyFmt  = $monthly ? 'YYYY-MM' : 'YYYY-MM-DD';

    [$where, $params] = movement_filters($from, $to, $f);
    $params[':tz'] = APP_TIMEZONE;

    $stmt = db()->prepare(
        "SELECT to_char(mm.detected_at AT TIME ZONE :tz, '$keyFmt') AS k,
                COALESCE(ma.explanation_status, 'pending') AS s, COUNT(*) AS n
         " . MOVEMENT_JOINS . "
          WHERE $where
       GROUP BY 1, 2"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $counts = [];
    foreach ($rows as $r) {
        $counts[$r['k']][$r['s']] = (int) $r['n'];
    }

    // Build every day/month in the range so empty days show as gaps, starting at the first data point for huge ranges.
    $cursor = $from->setTimezone($tz);
    if ($counts && $monthly) {
        $earliest = new DateTimeImmutable(min(array_keys($counts)) . '-01', $tz);
        $cursor   = max($cursor, $earliest);
    }
    $cursor = $monthly ? $cursor->modify('first day of this month')->setTime(0, 0) : $cursor->setTime(0, 0);
    $end    = $to->setTimezone($tz);

    $labels   = [];
    $datasets = array_fill_keys(CHART_STATUSES, []);
    while ($cursor <= $end && count($labels) < 400) {
        $key      = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
        $labels[] = $cursor->format($monthly ? 'M Y' : 'M j');
        foreach (CHART_STATUSES as $s) {
            $datasets[$s][] = $counts[$key][$s] ?? 0;
        }
        $cursor = $cursor->modify($monthly ? '+1 month' : '+1 day');
    }

    $totals = array_map('array_sum', $datasets);
    return ['labels' => $labels, 'datasets' => $datasets, 'totals' => $totals, 'total' => array_sum($totals)];
}

/** Drop-size histogram, hour-of-day pattern, and summary numbers. */
function chart_movement_stats(DateTimeImmutable $from, DateTimeImmutable $to, array $f = []): array
{
    [$where, $params] = movement_filters($from, $to, $f);
    $params[':tz'] = APP_TIMEZONE;

    $stmt = db()->prepare(
        "SELECT mm.drop_percentage_points AS pts, mm.dropped_side AS side,
                EXTRACT(HOUR FROM mm.detected_at AT TIME ZONE :tz)::int AS h
         " . MOVEMENT_JOINS . "
          WHERE $where"
    );
    $stmt->execute($params);

    $bins   = ['5–7.5' => 7.5, '7.5–10' => 10, '10–15' => 15, '15–20' => 20, '20–30' => 30, '30+' => INF];
    $hist   = array_fill_keys(array_keys($bins), 0);
    $hours  = array_fill(0, 24, 0);
    $sides  = ['yes' => 0, 'no' => 0];
    $sum    = 0.0;
    $max    = null;
    $total  = 0;

    foreach ($stmt->fetchAll() as $r) {
        $pts = (float) $r['pts'];
        foreach ($bins as $label => $upper) {
            if ($pts < $upper) {
                $hist[$label]++;
                break;
            }
        }
        $hours[(int) $r['h']]++;
        $sides[$r['side']]++;
        $sum += $pts;
        $max  = max($max ?? 0, $pts);
        $total++;
    }

    $hourLabels = array_map(fn($h) => ($h % 12 ?: 12) . ($h < 12 ? 'a' : 'p'), range(0, 23));

    return [
        'total' => $total,
        'avg'   => $total ? $sum / $total : null,
        'max'   => $max,
        'sides' => $sides,
        'hist'  => ['labels' => array_map(fn($l) => $l . ' pts', array_keys($hist)), 'values' => array_values($hist)],
        'hours' => ['labels' => $hourLabels, 'values' => $hours],
    ];
}

/** How often each keyword matched in saved explanations. */
function chart_keyword_freq(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null, int $limit = 12): array
{
    $where  = ["ma.explanation_status = 'article_found'"];
    $params = [];
    if ($from) {
        $where[] = 'mm.detected_at >= :from';
        $params[':from'] = $from->format('c');
    }
    if ($to) {
        $where[] = 'mm.detected_at <= :to';
        $params[':to'] = $to->format('c');
    }
    $stmt = db()->prepare(
        'SELECT k.kw, COUNT(*) AS n
           FROM movement_analysis ma
           JOIN market_movements mm ON mm.id = ma.movement_id
     CROSS JOIN LATERAL jsonb_array_elements_text(ma.matched_keywords) AS k(kw)
          WHERE ' . implode(' AND ', $where) . '
       GROUP BY k.kw
       ORDER BY n DESC, k.kw
          LIMIT ' . max(1, $limit)
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return ['labels' => array_column($rows, 'kw'), 'values' => array_map('intval', array_column($rows, 'n'))];
}

/** Scan runs over the last $hours for the health chart. */
function chart_scan_health(int $hours = 48): array
{
    $hours = max(1, $hours);
    $rows  = db()->query(
        "SELECT started_at, status, markets_found, movements_detected,
                EXTRACT(EPOCH FROM (finished_at - started_at))::int AS dur
           FROM scan_runs
          WHERE started_at >= NOW() - INTERVAL '$hours hours'
       ORDER BY started_at
          LIMIT 1000"
    )->fetchAll();

    return array_map(fn($r) => [
        't'      => iso($r['started_at']),
        'status' => $r['status'],
        'found'  => (int) $r['markets_found'],
        'moves'  => (int) $r['movements_detected'],
        'dur'    => $r['dur'] === null ? null : (int) $r['dur'],
    ], $rows);
}
