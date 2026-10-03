<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user = require_login();
$id   = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT m.*, e.event_title, e.event_start_time, e.kalshi_event_id
       FROM markets m JOIN events e ON e.id = m.event_id WHERE m.id = :id'
);
$stmt->execute([':id' => $id]);
$market = $stmt->fetch();
if (!$market) {
    http_response_code(404);
    page_header('Not found', $user);
    echo '<div class="empty card">' . icon('search') . '<p>Market not found.</p></div>';
    page_footer();
    exit;
}

/* ---------- time range ---------- */
$presets = ['24h' => '-24 hours', '7d' => '-7 days', '30d' => '-30 days', 'all' => null];
$range   = $_GET['range'] ?? ($market['status'] === 'open' ? '7d' : 'all');
$now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($range === 'custom') {
    $from = parse_local_datetime($_GET['from'] ?? null);
    $to   = parse_local_datetime($_GET['to'] ?? null) ?? $now;
    if (!$from) {
        $range = '7d';
    }
}
if ($range !== 'custom') {
    $range = array_key_exists($range, $presets) ? $range : '7d';
    $to    = $now;
    $from  = $presets[$range] ? $now->modify($presets[$range]) : new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC'));
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$p       = [':m' => $id, ':from' => $from->format('c'), ':to' => $to->format('c')];
$inRange = 'market_id = :m AND captured_at BETWEEN :from AND :to';

/* ---------- period analysis ---------- */
$q = db()->prepare("SELECT COUNT(*) AS n, MIN(yes_price) AS yes_low, MAX(yes_price) AS yes_high,
                           MIN(captured_at) AS first_at, MAX(captured_at) AS last_at
                      FROM market_snapshots WHERE $inRange");
$q->execute($p);
$stats = $q->fetch();

$edge = function (string $dir) use ($inRange, $p) {
    $s = db()->prepare("SELECT yes_price, no_price, volume, captured_at FROM market_snapshots
                         WHERE $inRange AND yes_price IS NOT NULL ORDER BY captured_at $dir LIMIT 1");
    $s->execute($p);
    return $s->fetch() ?: null;
};
$first     = $edge('ASC');
$last      = $edge('DESC');
$change    = ($first && $last) ? ((float) $last['yes_price'] - (float) $first['yes_price']) * 100 : null;
$volChange = ($first && $last && $first['volume'] !== null && $last['volume'] !== null)
    ? (int) $last['volume'] - (int) $first['volume'] : null;

/* ---------- chart series, downsampled to ~1500 points ---------- */
$spanSeconds = ($stats['first_at'] && $stats['last_at'])
    ? max(300, strtotime($stats['last_at']) - strtotime($stats['first_at'])) : 300;
$bucket = max(300, (int) ceil($spanSeconds / 1500));

$q = db()->prepare("SELECT DISTINCT ON (FLOOR(EXTRACT(EPOCH FROM captured_at) / $bucket))
                           captured_at, yes_price, no_price, yes_bid, yes_ask, last_trade_price, volume
                      FROM market_snapshots WHERE $inRange
                  ORDER BY FLOOR(EXTRACT(EPOCH FROM captured_at) / $bucket), captured_at DESC");
$q->execute($p);
$c = fn($v) => $v === null ? null : round((float) $v * 100, 2);
$series = array_map(fn($r) => [
    't'    => iso($r['captured_at']),
    'yes'  => $c($r['yes_price']),
    'no'   => $c($r['no_price']),
    'bid'  => ($r['yes_bid'] !== null && (float) $r['yes_bid'] > 0) ? $c($r['yes_bid']) : null,
    'ask'  => ($r['yes_ask'] !== null && (float) $r['yes_ask'] < 1) ? $c($r['yes_ask']) : null,
    'last' => ($r['last_trade_price'] !== null && (float) $r['last_trade_price'] > 0) ? $c($r['last_trade_price']) : null,
    'v'    => $r['volume'] === null ? null : (int) $r['volume'],
], $q->fetchAll());

$movements = fetch_movements(['market_id' => $id, 'from' => $from->format('c'), 'to' => $to->format('c')], 500);
$chartData = [
    'series'   => $series,
    'bucket'   => $bucket,
    'yesLabel' => 'YES' . ($market['yes_subtitle'] ? ' · ' . $market['yes_subtitle'] : ''),
    'noLabel'  => 'NO' . ($market['no_subtitle'] ? ' · ' . $market['no_subtitle'] : ''),
    'moves'    => array_map(fn($mv) => [
        'x'     => iso($mv['detected_at']),
        'y'     => round((float) $mv['current_price'] * 100, 2),
        'side'  => $mv['dropped_side'],
        'label' => strtoupper($mv['dropped_side']) . ' dropped ' . number_format((float) $mv['drop_percentage_points'], 1) . ' pts',
    ], $movements),
];
// Plot NO-side drops on the YES line (where the eye is) — a NO drop is a YES rise.
foreach ($chartData['moves'] as &$mv) {
    if ($mv['side'] === 'no') {
        $mv['y'] = round(100 - $mv['y'], 2);
    }
}
unset($mv);

/* ---------- latest snapshot, 24h change, matchup, watch status ---------- */
$q = db()->prepare('SELECT * FROM market_snapshots WHERE market_id = :m ORDER BY captured_at DESC LIMIT 1');
$q->execute([':m' => $id]);
$latest = $q->fetch() ?: null;

$q = db()->prepare("SELECT yes_price FROM market_snapshots WHERE market_id = :m AND captured_at <= NOW() - INTERVAL '24 hours'
                     ORDER BY captured_at DESC LIMIT 1");
$q->execute([':m' => $id]);
$yes24 = $q->fetchColumn();
$chg24 = ($latest && $latest['yes_price'] !== null && $yes24 !== false && $yes24 !== null)
    ? ((float) $latest['yes_price'] - (float) $yes24) * 100 : null;

$q = db()->prepare(
    'SELECT m.id, m.yes_subtitle, m.market_title, s.yes_price
       FROM markets m
  LEFT JOIN LATERAL (SELECT yes_price FROM market_snapshots WHERE market_id = m.id ORDER BY captured_at DESC LIMIT 1) s ON TRUE
      WHERE m.event_id = :e
   ORDER BY s.yes_price DESC NULLS LAST, m.id'
);
$q->execute([':e' => $market['event_id']]);
$siblings = $q->fetchAll();

$q = db()->prepare('SELECT 1 FROM watchlist_items WHERE user_id = :u AND market_id = :m');
$q->execute([':u' => (int) $user['id'], ':m' => $id]);
$watched = (bool) $q->fetchColumn();

log_activity('market_view', null, $id, ['range' => $range]);

$pct  = ($latest && $latest['yes_price'] !== null) ? (int) round((float) $latest['yes_price'] * 100) : null;
$self = $_SERVER['REQUEST_URI'] ?? url('/market.php', ['id' => $id]);
$name = $market['yes_subtitle'] ?: $market['market_title'];

page_header($name, $user);
?>
<nav class="crumbs"><a href="<?= e(url('/index.php')) ?>">Dashboard</a><span>›</span><?= e($market['event_title']) ?></nav>

<div class="page-head">
    <div>
        <h1><?= e($name) ?></h1>
        <div class="meta">
            <?= badge($market['status']) ?>
            <span class="muted"><?= e($market['market_ticker']) ?></span>
            <?php if ($market['status'] === 'open' && $market['close_time']): ?><span class="muted">closes <?= reltime($market['close_time']) ?></span><?php endif; ?>
            <?php if ($market['settlement_time']): ?><span class="muted">settled <?= e(fmt_time($market['settlement_time'])) ?></span><?php endif; ?>
        </div>
        <?php if ($market['yes_subtitle']): ?><p class="muted small"><?= e($market['market_title']) ?></p><?php endif; ?>
    </div>
    <?= watch_button($id, $watched, $self) ?>
</div>

<div class="grid grid-3">
    <section class="card hero span-2">
        <div class="ring" style="--p:<?= (int) ($pct ?? 0) ?>">
            <div><b><?= $pct === null ? '—' : $pct . '%' ?></b><span>implied YES</span></div>
        </div>
        <div class="hero-stats">
            <div><span>YES</span><b class="yes-text"><?= fmt_price($latest['yes_price'] ?? null) ?></b>
                <small>bid <?= fmt_price($latest['yes_bid'] ?? null) ?> · ask <?= fmt_price($latest['yes_ask'] ?? null) ?></small></div>
            <div><span>NO</span><b class="no-text"><?= fmt_price($latest['no_price'] ?? null) ?></b>
                <small>bid <?= fmt_price($latest['no_bid'] ?? null) ?> · ask <?= fmt_price($latest['no_ask'] ?? null) ?></small></div>
            <div><span>24h change</span><b><?= fmt_pp($chg24) ?></b><small>YES, points</small></div>
            <div><span>Last trade</span><b><?= fmt_price($latest['last_trade_price'] ?? null) ?></b>
                <small><?= $latest ? reltime($latest['captured_at']) : '—' ?></small></div>
            <div><span>Volume</span><b><?= fmt_int($latest['volume'] ?? null) ?></b><small>contracts</small></div>
            <div><span>Open interest</span><b><?= fmt_int($latest['open_interest'] ?? null) ?></b><small>contracts</small></div>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>Matchup</h2><span class="muted small">implied chance</span></div>
        <ul class="matchup">
        <?php foreach ($siblings as $s): $sp = $s['yes_price'] === null ? null : (float) $s['yes_price'] * 100; ?>
            <li class="<?= (int) $s['id'] === $id ? 'current' : '' ?>">
                <a href="<?= e(url('/market.php', ['id' => $s['id']])) ?>"><?= e($s['yes_subtitle'] ?: $s['market_title']) ?></a>
                <b><?= $sp === null ? '—' : number_format($sp, 1) . '%' ?></b>
                <div class="bar"><span style="width:<?= round($sp ?? 0, 1) ?>%"></span></div>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if ($market['event_start_time']): ?>
            <p class="muted small">Fight: <?= e(fmt_time($market['event_start_time'], 'D, M j · g:i A')) ?></p>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <div class="card-head wrap">
        <h2>Price history</h2>
        <form method="get" class="range">
            <input type="hidden" name="id" value="<?= $id ?>">
            <?php foreach (array_keys($presets) as $key): ?>
                <a class="pill<?= $range === $key ? ' active' : '' ?>" href="<?= e(url('/market.php', ['id' => $id, 'range' => $key])) ?>"><?= e($key) ?></a>
            <?php endforeach; ?>
            <input type="hidden" name="range" value="custom">
            <input type="datetime-local" name="from" value="<?= e(to_local_input($from)) ?>" aria-label="From">
            <input type="datetime-local" name="to" value="<?= e(to_local_input($to)) ?>" aria-label="To">
            <button>Apply</button>
            <button type="button" class="ghost" data-reset-zoom="m" title="Reset zoom"><?= icon('zoom') ?></button>
        </form>
    </div>

    <div class="stat-chips">
        <div><span>Start</span><b><?= fmt_price($first['yes_price'] ?? null) ?></b></div>
        <div><span>End</span><b><?= fmt_price($last['yes_price'] ?? null) ?></b></div>
        <div><span>Change</span><b><?= fmt_pp($change) ?></b></div>
        <div><span>Low – high</span><b><?= fmt_price($stats['yes_low']) ?> – <?= fmt_price($stats['yes_high']) ?></b></div>
        <div><span>Volume traded</span><b><?= $volChange === null ? '—' : fmt_int($volChange) ?></b></div>
        <div><span>Movements</span><b><?= count($movements) ?></b></div>
        <div><span>Snapshots</span><b><?= fmt_int($stats['n']) ?></b></div>
    </div>

    <div class="chart-box h-400"><canvas data-chart="price" data-source="d-price" data-group="m" data-empty="No snapshots in this period."></canvas></div>
    <p class="hint">Scroll to zoom · drag to pan · <span class="amber-text">▼</span> marks a detected movement · bars show volume traded per interval</p>

    <h3 class="sub-h">YES order book &amp; last trade</h3>
    <div class="chart-box h-200"><canvas data-chart="spread" data-source="d-price" data-group="m" data-empty="No order-book data in this period."></canvas></div>
    <?= json_script('d-price', $chartData) ?>
</section>

<section class="card">
    <div class="card-head"><h2>Movements in this period</h2><span class="muted small">drops of ≥ <?= (int) MOVEMENT_THRESHOLD_PP ?> pts between scans</span></div>
    <?php render_movements_table($movements, false); ?>
</section>

<?php page_footer();
