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
        'SELECT m.*, e.event_title, e.event_start_time, e.espn_data,
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
        'fight'    => espn_fight_info($m['espn_data'] ?? null, $m['yes_subtitle']),
    ];
}

/**
 * ESPN's saved view of the bout for the "Fight info" block, with this market's fighter first.
 * Null when the scanner hasn't found the fight on ESPN.
 */
function espn_fight_info(?string $json, ?string $subject): ?array
{
    $f = $json ? json_decode($json, true) : null;
    if (!is_array($f) || empty($f['fighters'])) {
        return null;
    }
    $ctx      = new FightContext($subject ?: null, [], 'yes');
    $fighters = [];
    foreach ($f['fighters'] as $c) {
        $line = $f['odds']['lines'][$c['id']] ?? null;
        $fighters[] = [
            'name'    => $c['name'],
            'record'  => $c['record'] ?? null,
            'winner'  => $c['winner'] ?? null,
            'subject' => $subject !== null && $ctx->whichFighter($c['name']) === $subject,
            'odds'    => $line,
            'implied' => $line === null ? null : round(EspnClient::impliedProbability((int) $line) * 100, 1),
            'stats'   => $f['stats'][$c['id']] ?? null,
            'rounds'  => $f['linescores'][$c['id']] ?? null,
        ];
    }
    usort($fighters, fn($a, $b) => $b['subject'] <=> $a['subject']);

    return [
        'event'        => $f['event'] ?? null,
        'weight_class' => $f['weight_class'] ?? null,
        'rounds'       => $f['rounds'] ?? null,
        'venue'        => $f['venue'] ?? null,
        'start'        => $f['start'] ?? null,
        'state'        => $f['state'] ?? null,
        'detail'       => $f['detail'] ?? null,
        'result'       => $f['result'] ?? null,
        'round'        => $f['round'] ?? null,
        'clock'        => $f['clock'] ?? null,
        'odds_source'  => $f['odds']['provider'] ?? null,
        'url'          => safe_external_url($f['url'] ?? null),
        'checked_at'   => $f['checked_at'] ?? null,
        'fighters'     => $fighters,
    ];
}

/** Which live sources the current .env turns on (web side; mirrors EvidenceCollector::enabledSources). */
function evidence_sources_configured(): array
{
    return [
        'espn'          => ESPN_ENABLED,
        'espn_plays'    => ESPN_ENABLED,
        'espn_news'     => ESPN_ENABLED,
        'kalshi_trades' => KALSHI_TRADES_ENABLED,
    ];
}

/**
 * The best-explained drop on this market: the one with the strongest supporting item from any source
 * (news article or live evidence), else the latest drop. Status "none" when the market never dropped ≥ 5 pp.
 */
function market_best_explanation(int $marketId): array
{
    $count = db()->prepare('SELECT COUNT(*) FROM market_movements WHERE market_id = :m');
    $count->execute([':m' => $marketId]);
    $drops = (int) $count->fetchColumn();
    if ($drops === 0) {
        return ['status' => 'none', 'drops' => 0];
    }

    $q = db()->prepare(
        "SELECT mm.id
           FROM market_movements mm
      LEFT JOIN movement_analysis ma ON ma.movement_id = mm.id
      LEFT JOIN LATERAL (SELECT MAX(score) AS s FROM movement_evidence ev
                          WHERE ev.movement_id = mm.id AND ev.status = 'found' AND ev.stance = 'supports') ev ON TRUE
          WHERE mm.market_id = :m
       ORDER BY GREATEST(CASE WHEN ma.explanation_status = 'article_found' THEN ma.relevance_score ELSE 0 END,
                         COALESCE(ev.s, 0)) DESC,
                mm.detected_at DESC
          LIMIT 1"
    );
    $q->execute([':m' => $marketId]);
    return movement_explanation((int) $q->fetchColumn()) + ['drops' => $drops];
}

/**
 * Evidence timeline for one drop:
 *   headline = the strongest item that fits the drop (any source)
 *   timeline = the best item from each source plus the drop itself, in time order
 *   checked  = what every source returned, including "nothing found" / "failed" / "not configured"
 */
function movement_explanation(int $movementId): array
{
    $q = db()->prepare(
        "SELECT mm.id, mm.detected_at, mm.dropped_side, mm.drop_percentage_points, mm.previous_price, mm.current_price, mm.movement_type,
                COALESCE(ma.explanation_status, 'pending') AS status, ma.relevance_score, ma.matched_keywords,
                a.title, a.url, a.description, a.source_name, a.published_at
           FROM market_movements mm
      LEFT JOIN movement_analysis ma ON ma.movement_id = mm.id
      LEFT JOIN articles a ON a.id = ma.article_id
          WHERE mm.id = :id"
    );
    $q->execute([':id' => $movementId]);
    $row = $q->fetch();
    if (!$row) {
        return ['status' => 'none', 'drops' => 0];
    }

    $side = strtoupper($row['dropped_side']);
    $pts  = (float) $row['drop_percentage_points'];
    $out  = [
        'status' => $row['status'],
        'drop'   => [
            'at'   => iso($row['detected_at']),
            'side' => $row['dropped_side'],
            'pts'  => $pts,
            'from' => cents_or_null($row['previous_price']),
            'to'   => cents_or_null($row['current_price']),
            'type' => $row['movement_type'] ?? 'drop',
        ],
    ];
    $isClose = ($row['movement_type'] ?? 'drop') === 'close';
    if ($isClose && $row['status'] === 'pending') {
        $out['status'] = 'close';   // TheNewsAPI isn't searched for a market close
    }

    $items   = [];
    $checked = [];

    // News (TheNewsAPI)
    $checked[] = ['source' => 'news', 'label' => SOURCE_LABELS['news'],
        'status' => $isClose && $row['status'] === 'pending' ? 'skipped' : $row['status'],
        'note'   => $isClose ? 'Not searched for a market close' : null];
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
        $items[] = [
            'source'   => 'news',
            'label'    => SOURCE_LABELS['news'],
            'at'       => iso($row['published_at']),
            'headline' => $row['title'],
            'detail'   => trim(($row['source_name'] ?: 'Unknown source') . ' · score ' . (int) $row['relevance_score']),
            'url'      => safe_external_url($row['url']),
            // keyword scores aren't directional; a confident match counts as fitting the drop
            'stance'   => (int) $row['relevance_score'] >= NEWS_CONFIDENT_SCORE ? 'supports' : 'neutral',
            'score'    => (int) $row['relevance_score'],
            'keywords' => $out['keywords'],
            'count'    => 1,
        ];
    }

    // Live sources
    $ev = db()->prepare('SELECT * FROM movement_evidence WHERE movement_id = :m');
    $ev->execute([':m' => $movementId]);
    $bySource = [];
    foreach ($ev->fetchAll() as $e) {
        $bySource[$e['source']] = $e;
    }
    foreach (evidence_sources_configured() as $src => $on) {
        $e = $bySource[$src] ?? null;
        if ($e === null) {
            $checked[] = ['source' => $src, 'label' => SOURCE_LABELS[$src], 'status' => $on ? 'pending' : 'not_configured', 'note' => null];
            continue;
        }
        $checked[] = [
            'source' => $src,
            'label'  => SOURCE_LABELS[$src],
            'status' => $e['status'],
            'note'   => $e['status'] === 'failed' ? $e['error_message'] : ($e['status'] !== 'found' ? $e['detail'] : null),
        ];
        if ($e['status'] !== 'found') {
            continue;
        }
        $items[] = [
            'source'   => $src,
            'label'    => SOURCE_LABELS[$src],
            'at'       => iso($e['occurred_at'] ?? $e['collected_at']),
            'headline' => $e['headline'],
            'detail'   => $e['detail'],
            'url'      => safe_external_url($e['url']),
            'stance'   => $e['stance'],
            'score'    => (int) $e['score'],
            'keywords' => json_decode((string) $e['matched_keywords'], true) ?: [],
            'count'    => (int) $e['item_count'],
        ];
    }

    // Headline: fits the drop first, then score, then source (ESPN fight > plays > news > ESPN news > trades).
    $prio = ['espn' => 5, 'espn_plays' => 4, 'news' => 3, 'espn_news' => 2, 'kalshi_trades' => 1];
    $rank = ['supports' => 2, 'neutral' => 1, 'contradicts' => 0];
    // (score ≥ 2 leaves out pure context like "fight hadn't started" or "no trades between scans")
    $candidates = array_values(array_filter($items, fn($i) => $i['stance'] !== 'contradicts' && ($i['score'] >= 2 || $i['source'] === 'news')));
    // Something that happened within 30 min of the drop (a knockdown, the result) beats older news.
    // Trades show *how* the price moved, not *why*, so they don't get that boost.
    $dropTs = strtotime((string) $row['detected_at']);
    $near   = fn(array $i) => (int) ($i['source'] !== 'kalshi_trades' && $i['at'] && abs(strtotime($i['at']) - $dropTs) <= 1800);
    usort($candidates, fn($a, $b) => [$rank[$b['stance']], $near($b), $b['score'], $prio[$b['source']]]
                                 <=> [$rank[$a['stance']], $near($a), $a['score'], $prio[$a['source']]]);
    $out['headline'] = $candidates[0] ?? null;

    $items[] = [
        'source' => 'drop', 'label' => $isClose ? 'Market closed' : 'Drop', 'at' => iso($row['detected_at']),
        'headline' => sprintf('%s −%s pp (%s¢ → %s¢)', $side, number_format($pts, 1),
            rtrim(rtrim(number_format((float) $row['previous_price'] * 100, 1), '0'), '.'),
            rtrim(rtrim(number_format((float) $row['current_price'] * 100, 1), '0'), '.')),
        'detail' => null, 'url' => null, 'stance' => 'neutral', 'score' => 0, 'keywords' => [], 'count' => 0,
    ];
    usort($items, fn($a, $b) => [strtotime((string) $a['at']), $a['source'] === 'drop'] <=> [strtotime((string) $b['at']), $b['source'] === 'drop']);

    $out['timeline'] = $items;
    $out['checked']  = $checked;
    if ($out['status'] !== 'article_found' && $out['headline'] !== null) {
        $out['status'] = 'evidence_found';
    }
    return $out;
}
