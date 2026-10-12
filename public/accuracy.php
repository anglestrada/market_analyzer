<?php
/**
 * Model accuracy: how often each model version picked the winner, over time, by finish type, plus a summary
 * of every scored fight (method, round, significant strikes / knockdowns / takedowns per fighter).
 *   /accuracy.php                 "All" tab (every version on one chart) + one tab per version
 *   /accuracy.php?v=v2            open on a version's tab
 *   /accuracy.php?source=live     only live predictions (also: backtest, history; default all, one row per fight)
 *   /accuracy.php?fights=all      list every fight instead of the latest 50
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user     = require_login();
$versions = FightModel::versions();
$colors   = accuracy_version_colors($versions);
$source   = array_key_exists($_GET['source'] ?? '', ACCURACY_SOURCES) ? $_GET['source'] : 'all';
$tab      = in_array($_GET['v'] ?? '', ['all', ...$versions], true) ? $_GET['v'] : 'all';
$showAll  = ($_GET['fights'] ?? '') === 'all';
$limit    = 50;

$pct  = fn(?float $p, int $d = 0) => $p === null ? '—' : number_format($p * 100, $d) . '%';
$num  = fn(?float $v, int $d = 3) => $v === null ? '—' : number_format($v, $d);
$half = fn(float $n) => rtrim(rtrim(number_format($n, 1), '0'), '.');   // 12, 12.5

$missing = false;
try {
    $rows = accuracy_rows(db(), $versions, $source);
} catch (PDOException $e) {
    error_log('[accuracy] ' . $e->getMessage());
    $rows = [];
    $missing = true;   // scoring tables not created yet (run schema.sql)
}

$byVersion = array_fill_keys($versions, []);
foreach ($rows as $r) {
    $byVersion[$r['model_version']][] = $r;
}
$summary = array_map('accuracy_summary', $byVersion);
$fights  = accuracy_fight_list($rows);
$stats   = accuracy_fight_stats(db(), array_map(fn($f) => $f['row']['fight_id'], $fights));

// Kalshi over every fight with a price (each fight once): the line every version is compared with.
$kalshiRows = array_reverse(array_map(fn($f) => ['correct' => null, 'k_correct' => $f['row']['k_correct'], 'cutoff_at' => $f['row']['cutoff_at']], $fights));
$kalshiSum  = ['n' => 0, 'sum' => 0.0];
foreach ($kalshiRows as $k) {
    if ($k['k_correct'] !== null) {
        $kalshiSum['n']++;
        $kalshiSum['sum'] += $k['k_correct'];
    }
}

page_header('Model accuracy', $user, ['nav' => 'accuracy']);

/* ---------- small renderers ---------- */

$sourcePills = function () use ($source): string {
    $h = '<div class="sel-actions acc-sources">';
    foreach (ACCURACY_SOURCES as $k => $label) {
        $h .= '<a class="pill' . ($k === $source ? ' active' : '') . '" data-keep-tab href="' . e(url('/accuracy.php', $k === 'all' ? [] : ['source' => $k])) . '">' . e($label) . '</a>';
    }
    return $h . '</div>';
};

$mark = fn(?float $c) => $c === null ? '<span class="muted">—</span>'
    : ($c >= 1 ? '<span class="acc-ok" title="Picked the winner">✓</span>'
    : ($c <= 0 ? '<span class="acc-bad" title="Picked the loser">✗</span>' : '<span class="muted" title="Exactly 50%">½</span>'));

/** Pick cell: the fighter the chance favoured, its chance, and ✓ / ✗. */
$pickCell = function (?array $pick, string $a, string $b) use ($pct, $mark): string {
    if ($pick === null) {
        return '<td class="muted">—</td>';
    }
    $p = (float) $pick['p'];
    $name = $p >= 0.5 ? $a : $b;
    return '<td class="nowrap">' . $mark($pick['correct']) . ' ' . e((string) fighter_last_name($name))
        . ' <span class="muted">' . $pct(max($p, 1 - $p)) . '</span></td>';
};

/** "134 – 70" for fighter A – fighter B; '—' when ESPN has no stats for the fight. */
$statPair = function (?array $sa, ?array $sb, callable $fmt): string {
    if ($sa === null && $sb === null) {
        return '<td class="num muted">—</td>';
    }
    return '<td class="num nowrap">' . $fmt($sa) . ' <span class="muted">–</span> ' . $fmt($sb) . '</td>';
};

$fightTable = function (array $list, ?string $only) use ($versions, $colors, $stats, $pct, $pickCell, $statPair, $mark, $limit, $showAll, $source): string {
    if ($only !== null) {
        $list = array_values(array_filter($list, fn($f) => isset($f['picks'][$only])));
    }
    $total = count($list);
    if (!$showAll) {
        $list = array_slice($list, 0, $limit);
    }
    $cols = $only !== null ? [$only] : $versions;
    ob_start(); ?>
    <div class="table-wrap">
    <table class="table acc-fights">
        <thead><tr>
            <th>Date</th><th>Fight</th>
            <?php foreach ($cols as $v): ?><th style="color:<?= e($colors[$v]) ?>">Model <?= e($v) ?></th><?php endforeach; ?>
            <th>Kalshi</th><th>Winner</th><th>Finish</th>
            <th class="num" title="Significant strikes landed, fighter A – fighter B">Sig. strikes</th>
            <th class="num" title="Knockdowns">KD</th>
            <th class="num" title="Takedowns landed / attempted">Takedowns</th>
            <th class="num" title="Control time">Control</th>
        </tr></thead>
        <tbody>
        <?php foreach ($list as $f):
            $r = $f['row'];
            $fs = $r['fight_id'] !== null ? ($stats[(int) $r['fight_id']] ?? []) : [];
            $sa = $fs[(int) $r['fighter_id']] ?? null;
            $sb = $r['opponent_id'] !== null ? ($fs[(int) $r['opponent_id']] ?? null) : null;
            $winner = match ($r['outcome']) {
                1 => fighter_last_name($r['a']),
                0 => fighter_last_name($r['b']),
                default => $r['result_type'] === 'no_contest' ? 'No contest' : 'Draw',
            };
            $finish = implode(' · ', array_filter([accuracy_finish_detail($r['method'], $r['method_detail']),
                                                    accuracy_round($r['round'], $r['end_clock_seconds'])]));
            $title = $r['event_name'] ?: $r['event_title'];
            $href = $r['event_id'] ? url('/prediction.php', ['event' => (int) $r['event_id']]) : null;
            $kp = $r['kalshi_p'];
            $ctrl = fn(?array $s) => $s === null ? '—' : sprintf('%d:%02d', intdiv((int) $s['control_seconds'], 60), (int) $s['control_seconds'] % 60);
        ?>
            <tr>
                <td class="nowrap small"><?= e(fmt_time($r['cutoff_at'], 'M j, Y')) ?></td>
                <td><?= $href ? '<a href="' . e($href) . '">' : '' ?><b><?= e($r['a']) ?></b> <span class="muted">vs</span> <b><?= e($r['b']) ?></b><?= $href ? '</a>' : '' ?>
                    <span class="cell-sub"><?= e((string) $title) ?><?= $r['weight_class'] ? ' · ' . e($r['weight_class']) : '' ?></span></td>
                <?php foreach ($cols as $v): ?><?= $pickCell($f['picks'][$v] ?? null, $r['a'], $r['b']) ?><?php endforeach; ?>
                <td class="nowrap"><?php if ($kp === null): ?><span class="muted">—</span><?php else: ?>
                    <?= $mark($r['k_correct']) ?> <?= e((string) fighter_last_name($kp >= 0.5 ? $r['a'] : $r['b'])) ?> <span class="muted"><?= $pct(max($kp, 1 - $kp)) ?></span><?php endif; ?></td>
                <td class="nowrap"><b><?= e((string) $winner) ?></b></td>
                <td><?php if ($r['method_cat']): ?><span class="acc-method acc-m-<?= e(strtolower(str_replace('/', '', $r['method_cat']))) ?>"><?= e($r['method_cat']) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?>
                    <?php if ($finish !== ''): ?><span class="cell-sub"><?= e($finish) ?></span><?php endif; ?></td>
                <?= $statPair($sa, $sb, fn($s) => $s === null ? '—' : (string) (int) $s['sig_strikes_landed']) ?>
                <?= $statPair($sa, $sb, fn($s) => $s === null ? '—' : (string) (int) $s['knockdowns']) ?>
                <?= $statPair($sa, $sb, fn($s) => $s === null ? '—' : (int) $s['takedowns_landed'] . '/' . (int) $s['takedowns_attempted']) ?>
                <?= $statPair($sa, $sb, $ctrl) ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($total > count($list)): ?>
        <p class="hint">Latest <?= count($list) ?> of <?= $total ?> fights · <a data-keep-tab href="<?= e(url('/accuracy.php', array_filter(['source' => $source === 'all' ? null : $source, 'fights' => 'all']))) ?>">show all</a></p>
    <?php endif; ?>
    <p class="hint">Stats are fighter A – fighter B (the fight's first-named fighter first), from ESPN. "—" = ESPN has no stats for that fight yet.</p>
    <?php return (string) ob_get_clean();
};

$methodTable = function (array $vs) use ($byVersion, $colors, $pct): string {
    $by = [];
    $cats = [];
    foreach ($vs as $v) {
        $by[$v] = accuracy_by_method($byVersion[$v]);
        $cats += array_fill_keys(array_keys($by[$v]), true);
    }
    $order = array_flip([...ACCURACY_METHODS, 'Unknown']);
    $cats = array_keys($cats);
    usort($cats, fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
    if (!$cats) {
        return '<p class="muted small">No finished fights yet.</p>';
    }
    $kalshi = count($vs) === 1;
    ob_start(); ?>
    <table class="table">
        <thead><tr><th>Finish</th><?php foreach ($vs as $v): ?><th class="num" style="color:<?= e($colors[$v]) ?>">Model <?= e($v) ?></th><?php endforeach; ?>
            <?php if ($kalshi): ?><th class="num kalshi-a">Kalshi</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
            <tr><td><?= e($c) ?></td>
                <?php foreach ($vs as $v): $m = $by[$v][$c] ?? null; ?>
                    <td class="num"><?= $m ? '<b>' . $pct($m['acc']) . '</b> <span class="muted small">of ' . $m['n'] . '</span>' : '<span class="muted">—</span>' ?></td>
                <?php endforeach; ?>
                <?php if ($kalshi): $m = $by[$vs[0]][$c] ?? null; ?>
                    <td class="num"><?= $m && $m['k_acc'] !== null ? '<b>' . $pct($m['k_acc']) . '</b> <span class="muted small">of ' . $m['k_n'] . '</span>' : '<span class="muted">—</span>' ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php return (string) ob_get_clean();
};
?>
<div class="page-head">
    <div>
        <h1>Model accuracy</h1>
        <p class="page-sub">How often each model version picked the winner of finished fights, next to the Kalshi favourite.</p>
    </div>
    <?= $sourcePills() ?>
</div>

<?php if ($missing): ?>
    <div class="empty card"><?= icon('target') ?><p>The scoring tables don't exist yet. Run <code>schema.sql</code>, then <code>php bin/model_report.php</code>.</p></div>
<?php elseif (!$rows): ?>
    <div class="empty card"><?= icon('target') ?><p>No scored fights<?= $source !== 'all' ? ' for this source' : '' ?> yet. Finished fights are scored after every scan; run <code>php bin/model_report.php --history</code> to score past UFC fights now.</p></div>
<?php else: ?>

<nav class="vtabs vtabs-color" role="tablist" aria-label="Model version">
    <button type="button" role="tab" data-vtab="all" aria-selected="<?= $tab === 'all' ? 'true' : 'false' ?>" style="--vc:var(--text-2)">All</button>
    <?php foreach ($versions as $v): $s = $summary[$v]; ?>
        <button type="button" role="tab" data-vtab="<?= e($v) ?>" aria-selected="<?= $v === $tab ? 'true' : 'false' ?>" style="--vc:<?= e($colors[$v]) ?>"
                title="<?= e(FightModel::VERSIONS[$v]['description']) ?>">
            <span class="dot" style="background:<?= e($colors[$v]) ?>"></span><?= e($v) ?><?php if ($s['acc'] !== null): ?> <span class="vtab-p" style="color:<?= e($colors[$v]) ?>"><?= $pct($s['acc']) ?></span><?php endif; ?>
        </button>
    <?php endforeach; ?>
    <span class="vtab-note muted small" data-vtab-desc><?= e($tab === 'all' ? 'Every version on the same chart' : FightModel::VERSIONS[$tab]['description']) ?></span>
</nav>

<?php /* ============================== All versions ============================== */ ?>
<div class="vpanel" role="tabpanel" data-vpanel="all" data-desc="Every version on the same chart"<?= $tab === 'all' ? '' : ' hidden' ?>>
    <div class="kpis">
        <?php foreach ($versions as $v): $s = $summary[$v]; ?>
            <?= kpi('Model ' . $v, '<span style="color:' . e($colors[$v]) . '">' . $pct($s['acc'], 1) . '</span>',
                $s['fights'] ? $half($s['correct'] + $s['ties'] / 2) . ' right · ' . $half($s['incorrect'] + $s['ties'] / 2) . ' wrong · ' . $s['fights'] . ' fights' : 'no finished fights yet') ?>
        <?php endforeach; ?>
        <?= kpi('Kalshi favourite', '<span class="kalshi-a">' . $pct($kalshiSum['n'] ? $kalshiSum['sum'] / $kalshiSum['n'] : null, 1) . '</span>',
            $kalshiSum['n'] ? $kalshiSum['n'] . ' fights with a pre-fight price' : 'no Kalshi prices on these fights') ?>
    </div>

    <section class="card">
        <div class="card-head"><h2>Accuracy over time</h2><span class="muted small">share of fights picked right so far</span></div>
        <div class="chart-box h-320"><canvas data-chart="accuracy" data-source="d-acc-all" data-empty="No finished fights yet."></canvas></div>
        <?php
        $series = [];
        foreach ($versions as $v) {
            $series[] = ['label' => 'Model ' . $v, 'color' => $colors[$v], 'points' => accuracy_cumulative($byVersion[$v])];
        }
        $series[] = ['label' => 'Kalshi', 'color' => ACCURACY_KALSHI_COLOR, 'dashed' => true, 'points' => accuracy_cumulative($kalshiRows, 'k_correct')];
        echo json_script('d-acc-all', ['series' => $series]);
        ?>
    </section>

    <div class="grid grid-2">
        <section class="card">
            <div class="card-head"><h2>Versions side by side</h2><span class="muted small">lower Brier / log loss is better</span></div>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Version</th><th class="num">Fights</th><th class="num">Right</th><th class="num">Wrong</th><th class="num">Accuracy</th>
                    <th class="num" title="Last 10 fights">Last 10</th><th class="num">Brier</th><th class="num">Log loss</th>
                    <th class="num" title="1 − model Brier / Kalshi Brier on fights with a Kalshi price; above 0 = beat the market">vs Kalshi</th></tr></thead>
                <tbody>
                <?php foreach ($versions as $v): $s = $summary[$v]; ?>
                    <tr>
                        <td><span class="dot" style="background:<?= e($colors[$v]) ?>"></span><b><?= e($v) ?></b></td>
                        <td class="num"><?= $s['fights'] ?></td>
                        <td class="num acc-ok"><?= $half($s['correct'] + $s['ties'] / 2) ?></td>
                        <td class="num acc-bad"><?= $half($s['incorrect'] + $s['ties'] / 2) ?></td>
                        <td class="num"><b style="color:<?= e($colors[$v]) ?>"><?= $pct($s['acc'], 1) ?></b></td>
                        <td class="num"><?= $pct($s['last10']) ?></td>
                        <td class="num"><?= $num($s['brier']) ?></td>
                        <td class="num"><?= $num($s['logloss']) ?></td>
                        <td class="num"><?= $s['skill'] === null ? '<span class="muted">—</span>' : '<b class="' . ($s['skill'] >= 0 ? 'up' : 'down') . '">' . e(sprintf('%+.3f', $s['skill'])) . '</b>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="hint">Versions can have different fight counts (a version added later has no live predictions for older fights).</p>
        </section>
        <section class="card">
            <div class="card-head"><h2>By finish</h2><span class="muted small">accuracy when the fight ended this way</span></div>
            <?= $methodTable($versions) ?>
        </section>
    </div>

    <section class="card">
        <div class="card-head"><h2>Fights</h2><span class="muted small">newest first · ✓ picked the winner, ✗ didn't</span></div>
        <?= $fightTable($fights, null) ?>
    </section>
</div>

<?php /* ============================== One version ============================== */ ?>
<?php foreach ($versions as $v): $s = $summary[$v]; $c = $colors[$v]; ?>
<div class="vpanel" role="tabpanel" data-vpanel="<?= e($v) ?>" data-desc="<?= e(FightModel::VERSIONS[$v]['description']) ?>"<?= $v === $tab ? '' : ' hidden' ?>>
    <?php if (!$s['fights'] && !$s['draws']): ?>
        <div class="empty card"><?= icon('target') ?><p>Model <?= e($v) ?> has no scored fights<?= $source !== 'all' ? ' for this source' : '' ?> yet.</p></div>
    <?php else: ?>
    <div class="kpis">
        <?= kpi('Accuracy', '<span style="color:' . e($c) . '">' . $pct($s['acc'], 1) . '</span>', $s['fights'] . ' finished fights' . ($s['draws'] ? ' · ' . $s['draws'] . ' draw/NC not counted' : '')) ?>
        <?= kpi('Right', '<span class="acc-ok">' . $half($s['correct'] + $s['ties'] / 2) . '</span>', 'picked the winner') ?>
        <?= kpi('Wrong', '<span class="acc-bad">' . $half($s['incorrect'] + $s['ties'] / 2) . '</span>', 'picked the loser') ?>
        <?= kpi('Last 10', $pct($s['last10']), 'most recent fights') ?>
        <?= kpi('Kalshi on the same fights', '<span class="kalshi-a">' . $pct($s['k_acc'], 1) . '</span>',
            $s['k_fights'] ? 'model ' . $pct($s['m_acc_k'], 1) . ' on these ' . $s['k_fights'] : 'no Kalshi prices on these fights') ?>
        <?= kpi('Brier', $num($s['brier']), $s['k_brier'] !== null ? 'Kalshi ' . $num($s['k_brier']) . ' · lower is better' : 'lower is better (0.25 = always 50%)') ?>
    </div>

    <div class="grid grid-3">
        <section class="card span-2">
            <div class="card-head"><h2>Accuracy over time</h2><span class="muted small">model <?= e($v) ?> vs the Kalshi favourite, same fights</span></div>
            <div class="chart-box h-260"><canvas data-chart="accuracy" data-source="d-acc-<?= e($v) ?>" data-empty="No finished fights yet."></canvas></div>
            <?= json_script('d-acc-' . $v, ['series' => [
                ['label' => 'Model ' . $v, 'color' => $c, 'points' => accuracy_cumulative($byVersion[$v])],
                ['label' => 'Kalshi', 'color' => ACCURACY_KALSHI_COLOR, 'dashed' => true, 'points' => accuracy_cumulative($byVersion[$v], 'k_correct')],
            ]]) ?>
        </section>
        <section class="card">
            <div class="card-head"><h2>Right vs wrong</h2><span class="muted small">per month</span></div>
            <div class="chart-box h-260"><canvas data-chart="accuracyMonthly" data-source="d-accm-<?= e($v) ?>" data-empty="No finished fights yet."></canvas></div>
            <?= json_script('d-accm-' . $v, ['months' => accuracy_monthly($byVersion[$v])]) ?>
        </section>
    </div>

    <div class="grid grid-2">
        <section class="card">
            <div class="card-head"><h2>By finish</h2><span class="muted small">accuracy when the fight ended this way</span></div>
            <?= $methodTable([$v]) ?>
        </section>
        <section class="card">
            <div class="card-head"><h2>Confidence</h2><span class="muted small">when the model said X%, how often its pick won</span></div>
            <table class="table">
                <thead><tr><th>Model's chance for its pick</th><th class="num">Fights</th><th class="num">Average chance</th><th class="num">Pick won</th></tr></thead>
                <tbody>
                <?php foreach (accuracy_calibration($byVersion[$v]) as $label => $b): ?>
                    <tr><td><?= e($label) ?></td><td class="num"><?= $b['n'] ?></td><td class="num"><?= $pct($b['avg_p']) ?></td>
                        <td class="num"><?php if ($b['hit'] === null): ?><span class="muted">—</span><?php else: ?>
                            <b class="<?= $b['hit'] + 0.05 >= $b['avg_p'] ? 'up' : 'down' ?>"><?= $pct($b['hit']) ?></b><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="hint">Well calibrated = "Pick won" close to "Average chance". Green = at or above it, red = more than 5 points under.</p>
        </section>
    </div>

    <section class="card">
        <div class="card-head"><h2>Fights</h2><span class="muted small">newest first · ✓ picked the winner, ✗ didn't</span></div>
        <?= $fightTable($fights, $v) ?>
    </section>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<p class="hint"><b>Live</b> = the prediction saved before the fight · <b>Backtest</b> = the version rerun on a Kalshi fight with only pre-fight data ·
    <b>History</b> = past UFC fights between synced fighters (no Kalshi price). "All sources" keeps one per fight: live, else backtest, else history.</p>
<?php endif; ?>
<?php page_footer();
