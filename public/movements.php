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

page_header('Movements', $user);
?>
<div class="page-head">
    <div>
        <h1>Movements</h1>
        <p class="muted">Every time a YES or NO price dropped by at least <?= (int) MOVEMENT_THRESHOLD_PP ?> points between two consecutive scans.</p>
    </div>
</div>

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
<?php page_footer();
