<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user = require_login();
$utc  = new DateTimeZone('UTC');
$now  = new DateTimeImmutable('now', $utc);

$from   = parse_local_datetime($_GET['from'] ?? null) ?? $now->modify('-7 days');
$to     = parse_local_datetime($_GET['to'] ?? null) ?? $now;
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$status = in_array($_GET['status'] ?? '', CHART_STATUSES, true) ? $_GET['status'] : '';
$side   = in_array($_GET['side'] ?? '', ['yes', 'no'], true) ? $_GET['side'] : '';
$f      = ['status' => $status, 'side' => $side];

$rows     = fetch_movements(['from' => $from->format('c'), 'to' => $to->format('c')] + $f, 500);
$daily    = chart_movements_daily($from, $to, $f);
$stats    = chart_movement_stats($from, $to, $f);
$keywords = chart_keyword_freq($from, $to);

$analyzed = $daily['total'] - ($daily['totals']['pending'] ?? 0);
$explRate = $analyzed > 0 ? 100 * ($daily['totals']['article_found'] ?? 0) / $analyzed : null;

$preset = fn(string $label, string $mod) => '<a class="pill" href="' . e(url('/movements.php', [
    'from' => to_local_input($now->modify($mod)), 'to' => to_local_input($now), 'side' => $side, 'status' => $status,
])) . '">' . e($label) . '</a>';

$closed = db()->prepare(
    "SELECT m.id, m.market_title, m.yes_subtitle, m.status, m.close_time, m.settlement_time, e.event_title,
            s.yes_price, s.no_price,
            (SELECT COUNT(*) FROM market_movements mm WHERE mm.market_id = m.id) AS drops
       FROM markets m
       JOIN events e ON e.id = m.event_id
  LEFT JOIN LATERAL (SELECT yes_price, no_price FROM market_snapshots
                      WHERE market_id = m.id ORDER BY captured_at DESC LIMIT 1) s ON TRUE
      WHERE e.sport = :sport AND m.status <> 'open'
   ORDER BY COALESCE(m.settlement_time, m.close_time, m.updated_at) DESC
      LIMIT 100"
);
$closed->execute([':sport' => DEFAULT_SPORT]);
$closed = $closed->fetchAll();

page_header('Market history', $user);
?>
<header class="page-head">
    <div>
        <p class="eyebrow">UFC / Market history</p>
        <h1>Market history</h1>
        <p class="page-sub">Every YES or NO drop of ≥ <?= (int) MOVEMENT_THRESHOLD_PP ?> pp between two consecutive scans, plus closed and settled markets.</p>
    </div>
</header>

<form method="get" class="card filters">
    <div class="range"><?= $preset('24h', '-24 hours') ?><?= $preset('7d', '-7 days') ?><?= $preset('30d', '-30 days') ?><?= $preset('90d', '-90 days') ?></div>
    <label>From <input type="datetime-local" name="from" value="<?= e(to_local_input($from)) ?>"></label>
    <label>To <input type="datetime-local" name="to" value="<?= e(to_local_input($to)) ?>"></label>
    <label>Side
        <select name="side">
            <option value="">Both</option>
            <option value="yes" <?= $side === 'yes' ? 'selected' : '' ?>>YES</option>
            <option value="no" <?= $side === 'no' ? 'selected' : '' ?>>NO</option>
        </select>
    </label>
    <label>Explanation
        <select name="status">
            <option value="">Any</option>
            <?php foreach (CHART_STATUSES as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button>Apply</button>
</form>

<div class="kpis">
    <?= kpi('Movements', count_num($stats['total']), e(fmt_time($from->format('c'), 'M j')) . ' – ' . e(fmt_time($to->format('c'), 'M j')), 'amber', 'movements') ?>
    <?= kpi('Average drop', $stats['avg'] === null ? '—' : count_num($stats['avg'], 1, ' pts'), 'per movement', 'red', 'movements') ?>
    <?= kpi('Largest drop', $stats['max'] === null ? '—' : count_num($stats['max'], 1, ' pts'), 'single scan', 'violet', 'movements') ?>
    <?= kpi('Explained', $explRate === null ? '—' : count_num($explRate, 0, '%'),
        'YES ' . (int) $stats['sides']['yes'] . ' · NO ' . (int) $stats['sides']['no'], 'green', 'news') ?>
</div>

<div class="grid grid-3">
    <section class="card span-2">
        <div class="card-head"><h2>Movements over time</h2><span class="muted small">stacked by explanation</span></div>
        <div class="chart-box h-260"><canvas data-chart="movementsDaily" data-source="d-daily" data-empty="No movements in this range."></canvas></div>
        <?= json_script('d-daily', $daily) ?>
    </section>
    <section class="card">
        <div class="card-head"><h2>Explanations</h2></div>
        <div class="chart-box h-260"><canvas data-chart="statusDonut" data-source="d-daily" data-empty="No movements in this range."></canvas></div>
    </section>
</div>

<div class="grid grid-3">
    <section class="card">
        <div class="card-head"><h2>Drop size</h2><span class="muted small">points</span></div>
        <div class="chart-box h-220"><canvas data-chart="histogram" data-source="d-hist" data-empty="No movements in this range."></canvas></div>
        <?= json_script('d-hist', $stats['total'] ? $stats['hist'] : null) ?>
    </section>
    <section class="card">
        <div class="card-head"><h2>Time of day</h2><span class="muted small"><?= e(APP_TIMEZONE) ?></span></div>
        <div class="chart-box h-220"><canvas data-chart="hours" data-source="d-hours" data-empty="No movements in this range."></canvas></div>
        <?= json_script('d-hours', $stats['total'] ? $stats['hours'] : null) ?>
    </section>
    <section class="card">
        <div class="card-head"><h2>Top keywords</h2><span class="muted small">in matched articles</span></div>
        <div class="chart-box h-220"><canvas data-chart="keywords" data-source="d-kw" data-empty="No matched articles in this range."></canvas></div>
        <?= json_script('d-kw', $keywords['labels'] ? $keywords : null) ?>
    </section>
</div>

<section class="card">
    <div class="card-head">
        <h2>All movements</h2>
        <span class="muted small"><?= count($rows) ?><?= count($rows) === 500 ? ' (newest 500)' : '' ?> · click a column to sort</span>
    </div>
    <?php render_movements_table($rows); ?>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Closed &amp; settled markets</h2>
        <span class="panel-note">Kept permanently · final saved snapshot</span>
    </div>
    <?php if (!$closed): ?>
        <div class="empty-inline"><?= icon('markets') ?><div><b>No closed markets yet.</b>
            <p>When a market closes, the scanner saves a final snapshot and it moves here.</p></div></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table data-table sortable">
            <thead><tr><th>Market</th><th>Status</th><th class="num">Final YES</th><th class="num">Final NO</th><th class="num">Drops</th><th class="num">Closed</th></tr></thead>
            <tbody>
            <?php foreach ($closed as $c): $closedAt = $c['settlement_time'] ?: $c['close_time']; ?>
                <tr>
                    <td data-value="<?= e(strtolower((string) ($c['yes_subtitle'] ?: $c['market_title']))) ?>">
                        <a href="<?= e(url('/market.php', ['id' => $c['id']])) ?>"><?= e($c['yes_subtitle'] ?: $c['market_title']) ?></a>
                        <span class="cell-sub"><?= e($c['event_title']) ?></span>
                    </td>
                    <td><?= badge($c['status']) ?></td>
                    <td class="num" data-value="<?= (float) $c['yes_price'] ?>"><?= fmt_price($c['yes_price']) ?></td>
                    <td class="num" data-value="<?= (float) $c['no_price'] ?>"><?= fmt_price($c['no_price']) ?></td>
                    <td class="num" data-value="<?= (int) $c['drops'] ?>"><?= (int) $c['drops'] ?></td>
                    <td class="num" data-value="<?= $closedAt ? strtotime($closedAt) : 0 ?>"><?= e(fmt_time($closedAt, 'M j, Y')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php page_footer();
