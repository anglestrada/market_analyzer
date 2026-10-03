<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user = require_login();
$view = ($_GET['view'] ?? 'active') === 'closed' ? 'closed' : 'active';
$open = $view === 'active';

$order = $open
    ? 'e.event_start_time ASC NULLS LAST, e.id, m.yes_subtitle'
    : 'COALESCE(m.close_time, m.updated_at) DESC, e.id, m.yes_subtitle';

$stmt = db()->prepare(
    "SELECT m.id, m.market_title, m.yes_subtitle, m.status, m.close_time,
            e.id AS event_id, e.event_title, e.event_start_time,
            s.yes_price, s.no_price, s.volume, s.captured_at,
            d.yes_price AS yes_24h,
            lm.detected_at AS lm_at, lm.dropped_side AS lm_side, lm.drop_percentage_points AS lm_pts,
            (w.id IS NOT NULL) AS watched
       FROM markets m
       JOIN events e ON e.id = m.event_id
  LEFT JOIN LATERAL (SELECT yes_price, no_price, volume, captured_at FROM market_snapshots
                      WHERE market_id = m.id ORDER BY captured_at DESC LIMIT 1) s ON TRUE
  LEFT JOIN LATERAL (SELECT yes_price FROM market_snapshots
                      WHERE market_id = m.id AND captured_at <= NOW() - INTERVAL '24 hours'
                      ORDER BY captured_at DESC LIMIT 1) d ON TRUE
  LEFT JOIN LATERAL (SELECT detected_at, dropped_side, drop_percentage_points FROM market_movements mm
                      WHERE mm.market_id = m.id ORDER BY detected_at DESC LIMIT 1) lm ON TRUE
  LEFT JOIN watchlist_items w ON w.market_id = m.id AND w.user_id = :uid
      WHERE e.sport = :sport AND " . ($open ? "m.status = 'open'" : "m.status <> 'open'") . "
   ORDER BY $order
      LIMIT 400"
);
$stmt->execute([':uid' => (int) $user['id'], ':sport' => DEFAULT_SPORT]);
$rows = $stmt->fetchAll();

$spark = $open ? chart_series(array_column($rows, 'id'), 24, 40) : [];

$events = [];
foreach ($rows as &$row) {
    $row['chg']   = ($row['yes_price'] !== null && $row['yes_24h'] !== null)
        ? ((float) $row['yes_price'] - (float) $row['yes_24h']) * 100 : null;
    $row['spark'] = array_column($spark[$row['id']] ?? [], 'y');
    $events[$row['event_id']]['title'] ??= $row['event_title'];
    $events[$row['event_id']]['start'] ??= $row['event_start_time'];
    $events[$row['event_id']]['markets'][] = $row;
}
unset($row);

/* ---------- dashboard numbers (active view only) ---------- */
if ($open) {
    $k = db()->prepare(
        "SELECT (SELECT COUNT(*) FROM events WHERE sport = :s1 AND status = 'open') AS open_events,
                (SELECT COUNT(*) FROM market_movements WHERE detected_at >= NOW() - INTERVAL '24 hours') AS mv24,
                (SELECT COUNT(*) FROM market_movements WHERE detected_at >= NOW() - INTERVAL '48 hours'
                                                         AND detected_at <  NOW() - INTERVAL '24 hours') AS mv_prev,
                (SELECT COUNT(*) FROM markets m JOIN events e ON e.id = m.event_id WHERE e.sport = :s2) AS tracked"
    );
    $k->execute([':s1' => DEFAULT_SPORT, ':s2' => DEFAULT_SPORT]);
    $kpi = $k->fetch();

    $now      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $daily    = chart_movements_daily($now->modify('-29 days'), $now);
    $analyzed = $daily['total'] - ($daily['totals']['pending'] ?? 0);
    $explRate = $analyzed > 0 ? 100 * ($daily['totals']['article_found'] ?? 0) / $analyzed : null;

    $withChg = array_values(array_filter($rows, fn($r) => $r['chg'] !== null));
    usort($withChg, fn($a, $b) => $a['chg'] <=> $b['chg']);
    $drops = array_slice(array_values(array_filter($withChg, fn($r) => $r['chg'] < -0.05)), 0, 5);
    $gains = array_slice(array_reverse(array_values(array_filter($withChg, fn($r) => $r['chg'] > 0.05))), 0, 5);

    $recent = fetch_movements(['from' => gmdate('c', time() - 86400)], 8);
    $scan   = latest_scan();
}

$self = $_SERVER['REQUEST_URI'] ?? url('/index.php');

$moverRow = function (array $m) {
    ?>
    <li>
        <a href="<?= e(url('/market.php', ['id' => $m['id']])) ?>" class="mover-name">
            <?= e($m['yes_subtitle'] ?: $m['market_title']) ?>
            <small><?= e($m['event_title']) ?></small>
        </a>
        <?= sparkline_svg($m['spark'], 90, 28) ?>
        <span class="mover-price"><?= fmt_price($m['yes_price']) ?></span>
        <?= fmt_pp($m['chg']) ?>
    </li>
    <?php
};

page_header('Dashboard', $user, ['autorefresh' => $open ? 300 : 0]);
?>
<div class="page-head">
    <div>
        <h1>UFC markets</h1>
        <p class="muted">Kalshi fight markets, scanned every 5 minutes. Drops of ≥ <?= (int) MOVEMENT_THRESHOLD_PP ?> points are flagged and matched to news.</p>
    </div>
    <div class="tabs">
        <a href="<?= e(url('/index.php')) ?>" class="<?= $open ? 'active' : '' ?>">Active</a>
        <a href="<?= e(url('/index.php', ['view' => 'closed'])) ?>" class="<?= $open ? '' : 'active' ?>">Closed &amp; settled</a>
    </div>
</div>

<?php if ($open): ?>
<div class="kpis">
    <?= kpi('Open markets', count_num(count($rows)), count_num((int) $kpi['open_events']) . ' fights', 'accent', 'markets') ?>
    <?php $diff = (int) $kpi['mv24'] - (int) $kpi['mv_prev']; ?>
    <?= kpi('Movements · 24h', count_num((int) $kpi['mv24']),
        ($diff === 0 ? 'same as' : ($diff > 0 ? '<span class="down">+' . $diff . '</span> vs' : '<span class="up">' . $diff . '</span> vs')) . ' previous 24h',
        'amber', 'movements') ?>
    <?= kpi('Explained · 30d', $explRate === null ? '—' : count_num($explRate, 0, '%'),
        e((string) ($daily['totals']['article_found'] ?? 0)) . ' of ' . e((string) $analyzed) . ' analyzed', 'green', 'news') ?>
    <?= kpi('Last scan', $scan ? reltime($scan['started_at']) : '—',
        $scan ? badge($scan['status']) . ' · ' . (int) $scan['markets_found'] . ' markets' : 'run <code>php cron/scan.php</code>',
        $scan && $scan['status'] === 'failed' ? 'red' : 'sky', 'scans') ?>
</div>

<div class="grid grid-3">
    <section class="card span-2">
        <div class="card-head"><h2>Movements per day</h2><span class="muted small">last 30 days · by explanation</span></div>
        <div class="chart-box h-260"><canvas data-chart="movementsDaily" data-source="d-daily" data-empty="No movements in the last 30 days."></canvas></div>
        <?= json_script('d-daily', $daily) ?>
    </section>
    <section class="card">
        <div class="card-head"><h2>Explanations</h2><span class="muted small">30 days</span></div>
        <div class="chart-box h-260"><canvas data-chart="statusDonut" data-source="d-daily" data-empty="Nothing analyzed yet."></canvas></div>
    </section>
</div>

<?php if ($drops || $gains): ?>
<div class="grid grid-2">
    <section class="card">
        <div class="card-head"><h2><span class="down">▼</span> Biggest drops · 24h</h2><span class="muted small">YES price change, pts</span></div>
        <?php if ($drops): ?><ul class="movers"><?php foreach ($drops as $m) { $moverRow($m); } ?></ul>
        <?php else: ?><p class="muted">No markets fell in the last 24 hours.</p><?php endif; ?>
    </section>
    <section class="card">
        <div class="card-head"><h2><span class="up">▲</span> Biggest gains · 24h</h2><span class="muted small">YES price change, pts</span></div>
        <?php if ($gains): ?><ul class="movers"><?php foreach ($gains as $m) { $moverRow($m); } ?></ul>
        <?php else: ?><p class="muted">No markets rose in the last 24 hours.</p><?php endif; ?>
    </section>
</div>
<?php endif; ?>

<?php if ($recent): ?>
<section class="card">
    <div class="card-head"><h2>Latest movements</h2><a class="small" href="<?= e(url('/movements.php')) ?>">See all →</a></div>
    <?php render_movements_table($recent); ?>
</section>
<?php endif; ?>
<?php endif; ?>

<div class="toolbar">
    <h2 class="section-title"><?= $open ? 'Upcoming fights' : 'Closed & settled fights' ?> <span class="count"><?= count($events) ?></span></h2>
    <label class="search-box"><?= icon('search') ?>
        <input type="search" data-filter=".event-card" data-empty="#no-match" placeholder="Filter fighters or events…  ( / )">
    </label>
</div>

<?php if (!$events): ?>
    <div class="empty card"><?= icon('markets') ?>
        <p>No <?= $open ? 'open' : 'closed' ?> markets yet.<?= $open ? ' Run <code>php cron/scan.php</code> to fetch them.' : '' ?></p>
    </div>
<?php endif; ?>
<div id="no-match" class="empty card" hidden><?= icon('search') ?><p>No fights match that filter.</p></div>

<div class="event-grid">
<?php foreach ($events as $ev):
    $ms     = $ev['markets'];
    $search = strtolower($ev['title'] . ' ' . implode(' ', array_map(fn($m) => $m['yes_subtitle'] . ' ' . $m['market_title'], $ms)));
    $pair   = count($ms) === 2 && $ms[0]['yes_price'] !== null && $ms[1]['yes_price'] !== null;
    ?>
    <article class="card event-card" data-search="<?= e($search) ?>">
        <header class="event-head">
            <div>
                <h3><?= e($ev['title']) ?></h3>
                <div class="muted small"><?= e(fmt_time($ev['start'], 'D, M j · g:i A')) ?><?php if ($ev['start'] && $open): ?> · <?= reltime($ev['start']) ?><?php endif; ?></div>
            </div>
            <span class="muted small"><?= count($ms) ?> market<?= count($ms) === 1 ? '' : 's' ?></span>
        </header>

        <?php if ($pair):
            $a = (float) $ms[0]['yes_price'];
            $b = (float) $ms[1]['yes_price'];
            $share = $a + $b > 0 ? 100 * $a / ($a + $b) : 50; ?>
            <div class="versus">
                <div class="vs-labels">
                    <span><b><?= e($ms[0]['yes_subtitle'] ?: 'A') ?></b> <?= fmt_price($a) ?></span>
                    <span><?= fmt_price($b) ?> <b><?= e($ms[1]['yes_subtitle'] ?: 'B') ?></b></span>
                </div>
                <div class="vs-bar"><span style="width:<?= round($share, 1) ?>%"></span></div>
            </div>
        <?php endif; ?>

        <ul class="market-list">
        <?php foreach ($ms as $m):
            $recentMove = $m['lm_at'] && strtotime($m['lm_at']) > time() - 86400; ?>
            <li class="market-row">
                <a class="mr-name" href="<?= e(url('/market.php', ['id' => $m['id']])) ?>">
                    <?= e($m['yes_subtitle'] ?: $m['market_title']) ?>
                    <?php if ($recentMove): ?>
                        <span class="chip chip-alert" title="Movement <?= e(fmt_time($m['lm_at'])) ?>">▼ <?= strtoupper(e($m['lm_side'])) ?> −<?= e(number_format((float) $m['lm_pts'], 1)) ?></span>
                    <?php endif; ?>
                    <?= prob_bar($m['yes_price']) ?>
                </a>
                <?php if ($open): ?>
                    <div class="mr-spark"><?= sparkline_svg($m['spark']) ?></div>
                <?php else: ?>
                    <div class="mr-spark"><?= badge($m['status']) ?></div>
                <?php endif; ?>
                <div class="mr-price">
                    <b><?= fmt_price($m['yes_price']) ?></b>
                    <?= $open ? fmt_pp($m['chg']) : '<span class="muted small">final</span>' ?>
                </div>
                <?= watch_button((int) $m['id'], (bool) $m['watched'], $self, true) ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </article>
<?php endforeach; ?>
</div>

<?php page_footer();
