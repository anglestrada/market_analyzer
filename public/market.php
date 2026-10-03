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
    echo '<p>Market not found.</p>';
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
$p = [':m' => $id, ':from' => $from->format('c'), ':to' => $to->format('c')];
$inRange = 'market_id = :m AND captured_at BETWEEN :from AND :to';

/* ---------- history analysis for the selected period ---------- */
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
$first = $edge('ASC');
$last  = $edge('DESC');
$change = ($first && $last) ? ((float) $last['yes_price'] - (float) $first['yes_price']) * 100 : null;
$volChange = ($first && $last && $first['volume'] !== null && $last['volume'] !== null)
    ? (int) $last['volume'] - (int) $first['volume'] : null;

/* ---------- chart data, downsampled to ~1500 points ---------- */
$spanSeconds = max(300, ($stats['first_at'] && $stats['last_at'])
    ? strtotime($stats['last_at']) - strtotime($stats['first_at']) : 300);
$bucket = max(300, (int) ceil($spanSeconds / 1500));   // never finer than the 5-minute scan

$q = db()->prepare("SELECT DISTINCT ON (FLOOR(EXTRACT(EPOCH FROM captured_at) / $bucket))
                           captured_at, yes_price, no_price
                      FROM market_snapshots WHERE $inRange
                  ORDER BY FLOOR(EXTRACT(EPOCH FROM captured_at) / $bucket), captured_at DESC");
$q->execute($p);
$series = array_map(fn($r) => [
    't'   => (new DateTimeImmutable($r['captured_at']))->format(DATE_ATOM),
    'yes' => $r['yes_price'] === null ? null : round((float) $r['yes_price'] * 100, 2),
    'no'  => $r['no_price'] === null ? null : round((float) $r['no_price'] * 100, 2),
], $q->fetchAll());

$movements = fetch_movements(['market_id' => $id, 'from' => $from->format('c'), 'to' => $to->format('c')], 500);
$moveDots  = array_map(fn($mv) => [
    'x'     => (new DateTimeImmutable($mv['detected_at']))->format(DATE_ATOM),
    'y'     => round((float) $mv['current_price'] * 100, 2),
    'label' => strtoupper($mv['dropped_side']) . ' −' . number_format((float) $mv['drop_percentage_points'], 1) . ' pts',
], $movements);

/* ---------- latest snapshot & watch status ---------- */
$q = db()->prepare('SELECT * FROM market_snapshots WHERE market_id = :m ORDER BY captured_at DESC LIMIT 1');
$q->execute([':m' => $id]);
$latest = $q->fetch() ?: null;

$q = db()->prepare('SELECT 1 FROM watchlist_items WHERE user_id = :u AND market_id = :m');
$q->execute([':u' => (int) $user['id'], ':m' => $id]);
$watched = (bool) $q->fetchColumn();

log_activity('market_view', null, $id, ['range' => $range]);

$self = $_SERVER['REQUEST_URI'] ?? url('/market.php', ['id' => $id]);
page_header($market['yes_subtitle'] ?: $market['market_title'], $user);
?>
<div class="page-head">
    <div>
        <div class="muted small"><a href="<?= e(url('/index.php')) ?>">Markets</a> › <?= e($market['event_title']) ?></div>
        <h1><?= e($market['market_title']) ?></h1>
        <div class="muted small">
            <?= e($market['market_ticker']) ?> · <?= badge($market['status']) ?>
            <?php if ($market['close_time']): ?> · closes <?= e(fmt_time($market['close_time'])) ?><?php endif; ?>
            <?php if ($market['settlement_time']): ?> · settled <?= e(fmt_time($market['settlement_time'])) ?><?php endif; ?>
        </div>
    </div>
    <?= watch_button($id, $watched, $self) ?>
</div>

<?php if ($latest): ?>
<div class="stats">
    <div><span>YES<?= $market['yes_subtitle'] ? ' · ' . e($market['yes_subtitle']) : '' ?></span><b><?= fmt_price($latest['yes_price']) ?></b>
        <small>bid <?= fmt_price($latest['yes_bid']) ?> / ask <?= fmt_price($latest['yes_ask']) ?></small></div>
    <div><span>NO<?= $market['no_subtitle'] ? ' · ' . e($market['no_subtitle']) : '' ?></span><b><?= fmt_price($latest['no_price']) ?></b>
        <small>bid <?= fmt_price($latest['no_bid']) ?> / ask <?= fmt_price($latest['no_ask']) ?></small></div>
    <div><span>Last trade</span><b><?= fmt_price($latest['last_trade_price']) ?></b><small><?= e(fmt_time($latest['captured_at'])) ?></small></div>
    <div><span>Volume</span><b><?= fmt_int($latest['volume']) ?></b><small>open interest <?= fmt_int($latest['open_interest']) ?></small></div>
</div>
<?php endif; ?>

<section class="card">
    <div class="toolbar">
        <h2>Price history</h2>
        <form method="get" class="range">
            <input type="hidden" name="id" value="<?= $id ?>">
            <?php foreach (array_keys($presets) as $key): ?>
                <a class="pill<?= $range === $key ? ' active' : '' ?>" href="<?= e(url('/market.php', ['id' => $id, 'range' => $key])) ?>"><?= e($key) ?></a>
            <?php endforeach; ?>
            <input type="hidden" name="range" value="custom">
            <input type="datetime-local" name="from" value="<?= e(to_local_input($from)) ?>">
            <input type="datetime-local" name="to" value="<?= e(to_local_input($to)) ?>">
            <button>Apply</button>
        </form>
    </div>

    <div class="stats compact">
        <div><span>Start (YES)</span><b><?= fmt_price($first['yes_price'] ?? null) ?></b><small><?= e(fmt_time($first['captured_at'] ?? null)) ?></small></div>
        <div><span>End (YES)</span><b><?= fmt_price($last['yes_price'] ?? null) ?></b><small><?= e(fmt_time($last['captured_at'] ?? null)) ?></small></div>
        <div><span>Change</span><b><?= fmt_pp($change) ?></b><small>YES side</small></div>
        <div><span>Range (YES)</span><b><?= fmt_price($stats['yes_low']) ?> – <?= fmt_price($stats['yes_high']) ?></b><small>low – high</small></div>
        <div><span>Volume traded</span><b><?= $volChange === null ? '—' : fmt_int($volChange) ?></b><small>in period</small></div>
        <div><span>Movements</span><b><?= count($movements) ?></b><small><?= fmt_int($stats['n']) ?> snapshots</small></div>
    </div>

    <?php if ($series): ?>
        <div class="chart-wrap"><canvas id="priceChart"></canvas></div>
    <?php else: ?>
        <p class="muted">No snapshots in this period.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Movements (≥ <?= (int) MOVEMENT_THRESHOLD_PP ?>-point drops) in this period</h2>
    <?php render_movements_table($movements, false); ?>
</section>

<?php if ($series): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script>
(() => {
    const series = <?= json_encode($series, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const moves  = <?= json_encode($moveDots, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    new Chart(document.getElementById('priceChart'), {
        type: 'line',
        data: {
            datasets: [
                { label: 'YES', data: series.map(p => ({ x: p.t, y: p.yes })), borderColor: '#16a34a', backgroundColor: '#16a34a', pointRadius: 0, borderWidth: 2, tension: 0.15, spanGaps: true },
                { label: 'NO',  data: series.map(p => ({ x: p.t, y: p.no  })), borderColor: '#dc2626', backgroundColor: '#dc2626', pointRadius: 0, borderWidth: 2, tension: 0.15, spanGaps: true },
                { label: 'Movement', type: 'scatter', data: moves, backgroundColor: '#f59e0b', borderColor: '#92400e', pointRadius: 6, pointStyle: 'triangle', rotation: 180 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'nearest', intersect: false },
            scales: {
                x: { type: 'time', time: { tooltipFormat: 'MMM d, yyyy h:mm a' } },
                y: { min: 0, max: 100, ticks: { callback: v => v + '¢' } }
            },
            plugins: {
                tooltip: { callbacks: { label: c => c.raw.label ? c.raw.label + ' (' + c.raw.y + '¢)' : c.dataset.label + ': ' + c.parsed.y + '¢' } }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php page_footer();
