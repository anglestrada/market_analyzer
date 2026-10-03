<?php
declare(strict_types=1);

/**
 * Data for the Overview page and /api/market.php.
 * Prices leave this file in cents (0–100); changes are in percentage points.
 */

const OVERVIEW_RANGES = [
    '1h'  => ['-1 hour', '1 hour'],
    '24h' => ['-24 hours', '24 hours'],
    '7d'  => ['-7 days', '7 days'],
    '30d' => ['-30 days', '30 days'],
    'all' => [null, 'all time'],
];

function cents_or_null(mixed $v): ?float
{
    return ($v === null || $v === '') ? null : round((float) $v * 100, 2);
}

/** Open markets with latest + previous snapshot and the latest drop's news status. */
function overview_markets(int $userId): array
{
    $stmt = db()->prepare(
        "SELECT m.id, m.market_title, m.yes_subtitle, m.no_subtitle,
                e.event_title, e.event_start_time,
                s.yes_price, s.no_price, s.captured_at,
                p.yes_price AS prev_yes, p.no_price AS prev_no,
                lm.detected_at AS lm_at, lm.dropped_side AS lm_side, lm.drop_percentage_points AS lm_pts,
                COALESCE(lma.explanation_status, CASE WHEN lm.id IS NOT NULL THEN 'pending' END) AS news_status,
                (w.id IS NOT NULL) AS watched
           FROM markets m
           JOIN events e ON e.id = m.event_id
      LEFT JOIN LATERAL (SELECT yes_price, no_price, captured_at FROM market_snapshots
                          WHERE market_id = m.id ORDER BY captured_at DESC LIMIT 1) s ON TRUE
      LEFT JOIN LATERAL (SELECT yes_price, no_price FROM market_snapshots
                          WHERE market_id = m.id ORDER BY captured_at DESC OFFSET 1 LIMIT 1) p ON TRUE
      LEFT JOIN LATERAL (SELECT id, detected_at, dropped_side, drop_percentage_points FROM market_movements
                          WHERE market_id = m.id ORDER BY detected_at DESC LIMIT 1) lm ON TRUE
      LEFT JOIN movement_analysis lma ON lma.movement_id = lm.id
      LEFT JOIN watchlist_items w ON w.market_id = m.id AND w.user_id = :uid
          WHERE e.sport = :sport AND m.status = 'open'
       ORDER BY e.event_start_time ASC NULLS LAST, e.id, m.yes_subtitle
          LIMIT 400"
    );
    $stmt->execute([':uid' => $userId, ':sport' => DEFAULT_SPORT]);

    return array_map(fn($r) => [
        'id'          => (int) $r['id'],
        'name'        => $r['yes_subtitle'] ?: $r['market_title'],
        'title'       => $r['market_title'],
        'event'       => $r['event_title'],
        'event_start' => $r['event_start_time'],
        'yes'         => cents_or_null($r['yes_price']),
        'no'          => cents_or_null($r['no_price']),
        'change'      => ($r['yes_price'] !== null && $r['prev_yes'] !== null)
            ? round(((float) $r['yes_price'] - (float) $r['prev_yes']) * 100, 2) : null,
        'lm_at'       => $r['lm_at'],
        'news_status' => $r['news_status'],
        'watched'     => (bool) $r['watched'],
    ], $stmt->fetchAll());
}

function overview_summary(int $userId): array
{
    $stmt = db()->prepare(
        "SELECT (SELECT COUNT(*) FROM market_movements mm JOIN markets m ON m.id = mm.market_id
                   JOIN events e ON e.id = m.event_id
                  WHERE e.sport = :s1 AND mm.detected_at >= NOW() - INTERVAL '24 hours') AS moves_24h,
                (SELECT COUNT(*) FROM market_movements mm JOIN markets m ON m.id = mm.market_id
                   JOIN events e ON e.id = m.event_id JOIN movement_analysis ma ON ma.movement_id = mm.id
                  WHERE e.sport = :s2 AND mm.detected_at >= NOW() - INTERVAL '24 hours'
                    AND ma.explanation_status = 'article_found') AS explained_24h,
                (SELECT COUNT(*) FROM watchlist_items w JOIN markets m ON m.id = w.market_id
                  WHERE w.user_id = :u1) AS watched,
                (SELECT COUNT(*) FROM watchlist_items w JOIN markets m ON m.id = w.market_id
                  WHERE w.user_id = :u2 AND m.status = 'open') AS watched_open"
    );
    $stmt->execute([':s1' => DEFAULT_SPORT, ':s2' => DEFAULT_SPORT, ':u1' => $userId, ':u2' => $userId]);
    return array_map('intval', $stmt->fetch());
}

/** Drops of ≥ 5 pp in the last $days, newest first. YES/NO are the prices right after the drop. */
function overview_drops(int $days = 7, int $limit = 25): array
{
    $rows = fetch_movements(['from' => gmdate('c', time() - $days * 86400)], $limit);
    return array_map(function ($r) {
        $cur = (float) $r['current_price'];
        $yes = $r['dropped_side'] === 'yes' ? $cur : 1 - $cur;
        return $r + ['yes_after' => round($yes * 100, 2), 'no_after' => round((1 - $yes) * 100, 2)];
    }, $rows);
}

/** Everything the Overview needs to draw one market: header, series, drop markers, best explanation. */
function market_payload(int $id, string $range, int $userId): ?array
{
    $range = array_key_exists($range, OVERVIEW_RANGES) ? $range : '24h';

    $stmt = db()->prepare(
        'SELECT m.*, e.event_title, e.event_start_time,
                (SELECT 1 FROM watchlist_items w WHERE w.market_id = m.id AND w.user_id = :u) AS watched
           FROM markets m JOIN events e ON e.id = m.event_id WHERE m.id = :id'
    );
    $stmt->execute([':u' => $userId, ':id' => $id]);
    $m = $stmt->fetch();
    if (!$m) {
        return null;
    }

    $snap = db()->prepare('SELECT yes_price, no_price, captured_at FROM market_snapshots
                            WHERE market_id = :m ORDER BY captured_at DESC LIMIT 2');
    $snap->execute([':m' => $id]);
    [$latest, $prev] = $snap->fetchAll() + [null, null];

    $change = fn(string $side) => ($latest && $prev && $latest[$side . '_price'] !== null && $prev[$side . '_price'] !== null)
        ? round(((float) $latest[$side . '_price'] - (float) $prev[$side . '_price']) * 100, 2) : null;

    // Range window
    $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $from = OVERVIEW_RANGES[$range][0] ? $now->modify(OVERVIEW_RANGES[$range][0]) : new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC'));
    $p    = [':m' => $id, ':from' => $from->format('c'), ':to' => $now->format('c')];

    $span = db()->prepare('SELECT MIN(captured_at) AS a, MAX(captured_at) AS b FROM market_snapshots
                            WHERE market_id = :m AND captured_at BETWEEN :from AND :to');
    $span->execute($p);
    $sp      = $span->fetch();
    $seconds = ($sp['a'] && $sp['b']) ? max(300, strtotime($sp['b']) - strtotime($sp['a'])) : 300;
    $bucket  = max(300, (int) ceil($seconds / 400));   // ≤ ~400 points, never finer than one scan

    $q = db()->prepare("SELECT DISTINCT ON (FLOOR(EXTRACT(EPOCH FROM captured_at) / $bucket))
                               captured_at, yes_price, no_price
                          FROM market_snapshots
                         WHERE market_id = :m AND captured_at BETWEEN :from AND :to
                      ORDER BY FLOOR(EXTRACT(EPOCH FROM captured_at) / $bucket), captured_at DESC");
    $q->execute($p);
    $series = array_map(fn($r) => [
        't' => iso($r['captured_at']), 'yes' => cents_or_null($r['yes_price']), 'no' => cents_or_null($r['no_price']),
    ], $q->fetchAll());

    $q = db()->prepare('SELECT detected_at, dropped_side, drop_percentage_points, current_price
                          FROM market_movements WHERE market_id = :m AND detected_at BETWEEN :from AND :to
                      ORDER BY detected_at');
    $q->execute($p);
    $moves = array_map(fn($r) => [
        'x' => iso($r['detected_at']), 'side' => $r['dropped_side'],
        'y' => cents_or_null($r['current_price']), 'pts' => (float) $r['drop_percentage_points'],
    ], $q->fetchAll());

    return [
        'market' => [
            'id'          => (int) $m['id'],
            'name'        => $m['yes_subtitle'] ?: $m['market_title'],
            'title'       => $m['market_title'],
            'event'       => $m['event_title'],
            'event_start' => iso($m['event_start_time']),
            'status'      => $m['status'],
            'ticker'      => $m['market_ticker'],
            'yes_label'   => $m['yes_subtitle'],
            'no_label'    => $m['no_subtitle'],
            'url'         => url('/market.php', ['id' => (int) $m['id']]),
        ],
        'watched' => (bool) $m['watched'],
        'latest'  => $latest ? [
            'yes' => cents_or_null($latest['yes_price']), 'no' => cents_or_null($latest['no_price']), 'at' => iso($latest['captured_at']),
        ] : null,
        'change'  => ['yes' => $change('yes'), 'no' => $change('no')],
        'range'   => ['key' => $range, 'label' => OVERVIEW_RANGES[$range][1]],
        'series'  => $series,
        'moves'   => $moves,
        'analysis' => market_best_explanation($id),
    ];
}

/**
 * Highest-scoring saved article across this market's drops. If no drop has an article,
 * returns the latest drop's status (no_explanation_found / news_search_failed / pending),
 * or status "none" when the market has never dropped ≥ 5 pp.
 */
function market_best_explanation(int $marketId): array
{
    $count = db()->prepare('SELECT COUNT(*) FROM market_movements WHERE market_id = :m');
    $count->execute([':m' => $marketId]);
    $drops = (int) $count->fetchColumn();
    if ($drops === 0) {
        return ['status' => 'none', 'drops' => 0];
    }

    $base = 'SELECT mm.detected_at, mm.dropped_side, mm.drop_percentage_points, mm.previous_price, mm.current_price,
                    COALESCE(ma.explanation_status, \'pending\') AS status, ma.relevance_score, ma.matched_keywords,
                    a.title, a.url, a.description, a.source_name, a.published_at
               FROM market_movements mm
          LEFT JOIN movement_analysis ma ON ma.movement_id = mm.id
          LEFT JOIN articles a ON a.id = ma.article_id
              WHERE mm.market_id = :m';

    $q = db()->prepare($base . " AND ma.explanation_status = 'article_found'
                                 ORDER BY ma.relevance_score DESC, mm.detected_at DESC LIMIT 1");
    $q->execute([':m' => $marketId]);
    $row = $q->fetch();
    if (!$row) {
        $q = db()->prepare($base . ' ORDER BY mm.detected_at DESC LIMIT 1');
        $q->execute([':m' => $marketId]);
        $row = $q->fetch();
    }

    $out = [
        'status' => $row['status'],
        'drops'  => $drops,
        'drop'   => [
            'at'   => iso($row['detected_at']),
            'side' => $row['dropped_side'],
            'pts'  => (float) $row['drop_percentage_points'],
            'from' => cents_or_null($row['previous_price']),
            'to'   => cents_or_null($row['current_price']),
        ],
    ];
    if ($row['status'] === 'article_found') {
        $out['article'] = [
            'title'        => $row['title'],
            'url'          => safe_external_url($row['url']),
            'description'  => $row['description'],
            'source'       => $row['source_name'],
            'published_at' => iso($row['published_at']),
        ];
        $out['score']    = (int) $row['relevance_score'];
        $out['keywords'] = json_decode((string) $row['matched_keywords'], true) ?: [];
    }
    return $out;
}
