<?php
/**
 * Fight prediction: one entry per fight (a Kalshi event holds one "Will X win?" market per fighter).
 *   /prediction.php              every open fight: both fighters' model and Kalshi chances
 *   /prediction.php?event=ID     one fight: chart with both fighters (model + Kalshi), breakdown, comparison, resumes
 *   /prediction.php?market=ID    same page, opened from a market (that fighter is shown first)
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user     = require_login();
$marketId = (int) ($_GET['market'] ?? 0);
$eventId  = (int) ($_GET['event'] ?? 0);
$model    = new FightModel(db());
$pct      = fn(?float $p, int $d = 0) => $p === null ? '—' : number_format($p * 100, $d) . '%';
$gapHtml  = function (?float $model, ?float $kalshi): string {
    if ($model === null || $kalshi === null) {
        return '<span class="muted">—</span>';
    }
    return fmt_pp(($model - $kalshi) * 100);
};
if ($marketId > 0 && $eventId <= 0) {
    $q = db()->prepare('SELECT event_id FROM markets WHERE id = :id');
    $q->execute([':id' => $marketId]);
    $eventId = (int) $q->fetchColumn();
    if ($eventId <= 0) {
        http_response_code(404);
        page_header('Not found', $user, ['nav' => 'prediction']);
        echo '<div class="empty card">' . icon('search') . '<p>Market not found.</p></div>';
        page_footer();
        exit;
    }
}

/* =====================================================================
 * List of open fights
 * ================================================================== */
if ($eventId <= 0) {
    $versions = FightModel::versions();
    $vcols = '';
    foreach ($versions as $i => $v) {   // latest prediction per version (versions are code constants, safe to inline)
        $vcols .= ",\n                (SELECT win_probability FROM predictions p WHERE p.market_id = m.id AND p.model_version = '$v'
                  ORDER BY created_at DESC LIMIT 1) AS model_$i";
    }
    $rows = db()->query(
        "SELECT m.id, m.event_id, m.yes_subtitle, m.market_title, e.event_title, e.event_start_time, mf.fighter_id,
                (SELECT yes_price FROM market_snapshots s WHERE s.market_id = m.id ORDER BY captured_at DESC LIMIT 1) AS kalshi,
                (SELECT created_at FROM predictions p WHERE p.market_id = m.id ORDER BY created_at DESC LIMIT 1) AS model_at$vcols
           FROM markets m
           JOIN events e ON e.id = m.event_id
      LEFT JOIN market_fighters mf ON mf.market_id = m.id
          WHERE e.sport = 'UFC' AND m.status = 'open'
       ORDER BY e.event_start_time ASC NULLS LAST, e.id, m.id"
    )->fetchAll();

    // Group the per-fighter markets into fights.
    $fights = [];
    foreach ($rows as $r) {
        $fights[(int) $r['event_id']] ??= ['event' => $r, 'sides' => []];
        $fights[(int) $r['event_id']]['sides'][] = $r;
    }
    foreach ($fights as &$f) {
        $s0 = $f['sides'][0];
        $s1 = $f['sides'][1] ?? null;
        $f['a'] = $s0['yes_subtitle'] ?: $s0['market_title'];
        $f['b'] = $s1 ? ($s1['yes_subtitle'] ?: $s1['market_title']) : null;
        $f['ka'] = $s0['kalshi'] !== null ? (float) $s0['kalshi'] : null;
        $f['kb'] = $s1 && $s1['kalshi'] !== null ? (float) $s1['kalshi'] : ($f['ka'] !== null ? 1 - $f['ka'] : null);
        // Each version is one number per fight: take whichever side has one, the other side is 1 − p.
        foreach ($versions as $i => $v) {
            $c = "model_$i";
            $ma = $s0[$c] !== null ? (float) $s0[$c] : ($s1 && $s1[$c] !== null ? 1 - (float) $s1[$c] : null);
            $f['m'][$v] = [$ma, $ma !== null ? 1 - $ma : null];
        }
        [$f['ma'], $f['mb']] = $f['m'][FightModel::DEFAULT_VERSION];
        $f['at'] = max((string) $s0['model_at'], (string) ($s1['model_at'] ?? '')) ?: null;
        $f['linked'] = (bool) ($s0['fighter_id'] || ($s1['fighter_id'] ?? null));
    }
    unset($f);

    page_header('Fight prediction', $user, ['nav' => 'prediction']);
    ?>
    <div class="page-head">
        <div>
            <h1>Fight prediction</h1>
            <p class="page-sub">The model's chance for each fighter next to the Kalshi price. Research only, not betting advice.</p>
        </div>
    </div>
    <?php
    try {
        $score = db()->query("SELECT * FROM model_scorecard WHERE fights > 0 ORDER BY model_version DESC, source")->fetchAll();
    } catch (PDOException) {
        $score = [];   // scoring tables not created yet (run schema.sql)
    }
    if ($score): ?>
    <section class="card">
        <div class="card-head"><h2>How the model has done</h2><span class="muted small">finished fights · lower Brier is better (0.25 = always 50%) · <a href="<?= e(url('/accuracy.php')) ?>">Model accuracy</a></span></div>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Version</th><th class="num">Fights</th><th class="num">Picked winner</th><th class="num">Brier</th>
                <th class="num">Kalshi picked winner</th><th class="num">Kalshi Brier</th><th class="num" title="1 − model Brier / Kalshi Brier on the same fights; above 0 = beat the market">Skill vs Kalshi</th></tr></thead>
            <tbody>
            <?php foreach ($score as $sc): ?>
                <tr>
                    <td><b><?= e($sc['model_version']) ?></b> <span class="muted small"><?= e($sc['source'] === 'live' ? 'live' : 'backtest') ?></span></td>
                    <td class="num"><?= (int) $sc['fights'] ?></td>
                    <td class="num"><?= $sc['model_accuracy_pct'] !== null ? e($sc['model_accuracy_pct']) . '%' : '—' ?></td>
                    <td class="num"><?= e((string) ($sc['model_brier'] ?? '—')) ?></td>
                    <td class="num"><?= $sc['kalshi_accuracy_pct'] !== null ? e($sc['kalshi_accuracy_pct']) . '%' : '—' ?></td>
                    <td class="num"><?= e((string) ($sc['kalshi_brier'] ?? '—')) ?></td>
                    <td class="num"><?php $sk = $sc['brier_skill_vs_kalshi']; echo $sk === null ? '—' : '<b class="' . ((float) $sk >= 0 ? 'up' : 'down') . '">' . e(sprintf('%+.3f', (float) $sk)) . '</b>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="hint"><b>live</b> = predictions saved before the fight · <b>backtest</b> = the current version rerun with only pre-fight data. Full report: <code>php bin/model_report.php</code></p>
    </section>
    <?php endif; ?>
    <?php if (!$fights): ?>
        <div class="empty card"><?= icon('target') ?><p>No open UFC markets yet. Run a scan first.</p></div>
    <?php else: ?>
    <section class="card">
        <div class="table-wrap">
        <table class="table fights-table">
            <thead><tr><th>Fight</th><th>Fighter</th><?php foreach ($versions as $v): ?><th class="num">Model <?= e($v) ?></th><?php endforeach; ?>
                <th class="num">Kalshi</th><th class="num" title="<?= e(FightModel::DEFAULT_VERSION) ?> minus Kalshi">Gap</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($fights as $id => $f): $href = url('/prediction.php', ['event' => $id]); ?>
                <tr class="fight-a">
                    <td rowspan="<?= $f['b'] ? 2 : 1 ?>"><a href="<?= e($href) ?>"><b><?= e($f['a']) ?><?= $f['b'] ? ' vs ' . e($f['b']) : '' ?></b></a>
                        <span class="cell-sub"><?= e($f['event']['event_title']) ?><?= $f['event']['event_start_time'] ? ' · ' . e(fmt_time($f['event']['event_start_time'], 'M j, g:i A')) : '' ?></span></td>
                    <td><span class="dot dot-a"></span><?= e($f['a']) ?></td>
                    <?php foreach ($versions as $v): ?><td class="num"><b class="model-a"><?= $pct($f['m'][$v][0]) ?></b></td><?php endforeach; ?>
                    <td class="num" data-value="<?= $f['ka'] ?? -1 ?>"><b class="kalshi-a"><?= $pct($f['ka']) ?></b></td>
                    <td class="num"><?= $gapHtml($f['ma'], $f['ka']) ?></td>
                    <td rowspan="<?= $f['b'] ? 2 : 1 ?>" class="small"><?= $f['at'] ? reltime($f['at']) : '<span class="muted">' . ($f['linked'] ? 'syncing' : 'not matched') . '</span>' ?></td>
                </tr>
                <?php if ($f['b']): ?>
                <tr class="fight-b">
                    <td><span class="dot dot-b"></span><?= e($f['b']) ?></td>
                    <?php foreach ($versions as $v): ?><td class="num"><b class="model-b"><?= $pct($f['m'][$v][1]) ?></b></td><?php endforeach; ?>
                    <td class="num"><b class="kalshi-b"><?= $pct($f['kb']) ?></b></td>
                    <td class="num"><?= $gapHtml($f['mb'], $f['kb']) ?></td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="hint">Fighter data comes from ESPN and fills in a little every scan. Run <code>php bin/fighters_sync.php</code> to fill it all at once.</p>
    </section>
    <?php endif;
    page_footer();
    exit;
}

/* =====================================================================
 * One fight
 * ================================================================== */
$stmt = db()->prepare(
    'SELECT m.*, e.event_title, e.event_start_time FROM markets m JOIN events e ON e.id = m.event_id
      WHERE m.event_id = :e ORDER BY m.id'
);
$stmt->execute([':e' => $eventId]);
$markets = $stmt->fetchAll();
if (!$markets) {
    http_response_code(404);
    page_header('Not found', $user, ['nav' => 'prediction']);
    echo '<div class="empty card">' . icon('search') . '<p>Fight not found.</p></div>';
    page_footer();
    exit;
}

// Fighter A = the market the page was opened from (or the first one the model can score); fighter B = the other.
$results = [];
foreach ($markets as $m) {
    $results[(int) $m['id']] = $model->predictMarket((int) $m['id']);
}
$primary = null;
foreach ($markets as $m) {
    if ((int) $m['id'] === $marketId) {
        $primary = $m;
    }
}
if ($primary === null) {
    foreach ($markets as $m) {
        if ($primary === null && $results[(int) $m['id']]['ok']) {
            $primary = $m;
        }
    }
}
$primary ??= $markets[0];
$aId = (int) $primary['id'];
$secondary = null;
foreach ($markets as $m) {
    if ($secondary === null && (int) $m['id'] !== $aId) {
        $secondary = $m;
    }
}
$bId = $secondary ? (int) $secondary['id'] : null;

$res   = $results[$aId];
$nameA = $primary['yes_subtitle'] ?: $primary['market_title'];
$nameB = $secondary ? ($secondary['yes_subtitle'] ?: $secondary['market_title']) : ($res['b']['name'] ?? ($primary['no_subtitle'] ?: 'Opponent'));
$p     = $res['ok'] ? (float) $res['p'] : null;          // model chance for A; B = 1 − p

$latest = function (?int $id): ?array {
    if ($id === null) {
        return null;
    }
    $q = db()->prepare('SELECT yes_price, captured_at FROM market_snapshots WHERE market_id = :m ORDER BY captured_at DESC LIMIT 1');
    $q->execute([':m' => $id]);
    $r = $q->fetch();
    return $r && $r['yes_price'] !== null ? ['p' => (float) $r['yes_price'], 'at' => $r['captured_at']] : null;
};
$snapA = $latest($aId);
$snapB = $latest($bId);
$kA = $snapA['p'] ?? null;
$kB = $snapB['p'] ?? ($kA !== null ? 1 - $kA : null);   // only one market on Kalshi → its NO side

// One tab per model version (v1, v2, …); each has its own numbers, chart and breakdown. Default: v1.
$versions = FightModel::versions();
$tab      = in_array($_GET['v'] ?? '', $versions, true) ? $_GET['v'] : FightModel::DEFAULT_VERSION;
$panels   = [];
foreach ($versions as $v) {
    $vr = $v === FightModel::DEFAULT_VERSION ? $res : (new FightModel(db(), $v))->predictMarket($aId);
    $vp = $vr['ok'] ? (float) $vr['p'] : null;
    // Bar chart: one bar per fighter (model), with that fighter's Kalshi YES price as a dotted line over their bar.
    $bars = [
        ['name' => $nameA, 'model' => $vp !== null ? round($vp * 100, 1) : null, 'kalshi' => $kA !== null ? round($kA * 100, 1) : null, 'side' => 'a'],
        ['name' => $nameB, 'model' => $vp !== null ? round((1 - $vp) * 100, 1) : null, 'kalshi' => $kB !== null ? round($kB * 100, 1) : null, 'side' => 'b'],
    ];
    $panels[$v] = ['res' => $vr, 'p' => $vp, 'bars' => $bars, 'hasChart' => $vp !== null || $kA !== null];
}

log_activity('market_view', null, $aId, ['source' => 'prediction']);
page_header('Prediction · ' . $nameA . ' vs ' . $nameB, $user, ['nav' => 'prediction']);

$reasonText = [
    'not_linked'  => ['Not matched to ESPN yet', "This fight's Kalshi markets haven't been matched to ESPN fighters. Matching runs with every scan; run <code>php bin/fighters_sync.php --market=$aId</code> to do it now."],
    'no_opponent' => ['Opponent not matched', "The opponent couldn't be found on ESPN. Run <code>php bin/fighters_sync.php --market=$aId --relink</code>, or set <code>market_fighters.opponent_id</code> by hand (and <code>match_method = 'manual'</code>)."],
    'not_synced'  => ['Fighter data still syncing', "ESPN fighter data fills in a little every scan. Run <code>php bin/fighters_sync.php --market=$aId</code> to finish it now."],
    'no_history'  => ['No fight history on ESPN', 'One of the fighters has no completed fights on ESPN, so the model has nothing to score.'],
];
$side = function (string $name, string $cls, ?float $model, ?float $kalshi, ?array $snap) use ($pct, $gapHtml): string {
    ob_start(); ?>
    <div class="pred-fighter pred-<?= $cls ?>">
        <h2><span class="dot dot-<?= $cls ?>"></span><?= e($name) ?></h2>
        <div class="pred-nums">
            <div><span class="pred-label">Model</span><b class="pred-big model-<?= $cls ?>"><?= $pct($model, 1) ?></b></div>
            <div><span class="pred-label">Kalshi</span><b class="pred-big kalshi-<?= $cls ?>"><?= $pct($kalshi, 1) ?></b>
                <span class="muted small"><?= $snap ? 'price ' . reltime($snap['at']) : '' ?></span></div>
            <div><span class="pred-label">Gap</span><b class="pred-big"><?= $gapHtml($model, $kalshi) ?></b></div>
        </div>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<nav class="crumbs"><a href="<?= e(url('/prediction.php')) ?>">Fight prediction</a><span>›</span><?= e($primary['event_title']) ?></nav>

<div class="page-head">
    <div>
        <h1><?= e($nameA) ?> <span class="muted">vs</span> <?= e($nameB) ?></h1>
        <div class="meta">
            <?= badge($primary['status']) ?>
            <?php if ($primary['event_start_time']): ?><span class="muted"><?= e(fmt_time($primary['event_start_time'], 'D, M j · g:i A')) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="sel-actions">
        <a class="pill" href="<?= e(url('/market.php', ['id' => $aId])) ?>"><?= e($nameA) ?> market</a>
        <?php if ($bId): ?><a class="pill" href="<?= e(url('/market.php', ['id' => $bId])) ?>"><?= e($nameB) ?> market</a><?php endif; ?>
        <a class="pill" href="<?= e(url('/index.php', ['market' => $aId])) ?>">Overview</a>
    </div>
</div>

<nav class="vtabs" role="tablist" aria-label="Model version">
    <?php foreach ($panels as $v => $pn): ?>
        <button type="button" role="tab" data-vtab="<?= e($v) ?>" aria-selected="<?= $v === $tab ? 'true' : 'false' ?>"
                title="<?= e(FightModel::VERSIONS[$v]['description']) ?>">
            Model <?= e($v) ?><?php if ($pn['p'] !== null): ?> <span class="vtab-p"><?= $pct($pn['p']) ?></span><?php endif; ?>
        </button>
    <?php endforeach; ?>
    <span class="vtab-note muted small" data-vtab-desc><?= e(FightModel::VERSIONS[$tab]['description']) ?></span>
</nav>

<?php foreach ($panels as $v => $pn):
    $res = $pn['res']; $p = $pn['p']; $bars = $pn['bars']; $hasChart = $pn['hasChart']; ?>
<div class="vpanel" role="tabpanel" data-vpanel="<?= e($v) ?>" data-desc="<?= e(FightModel::VERSIONS[$v]['description']) ?>"<?= $v === $tab ? '' : ' hidden' ?>>
<?php if (!$res['ok']): [$t, $txt] = $reasonText[$res['reason']] ?? ['Prediction unavailable', e($res['reason'])]; ?>
    <section class="card"><div class="news-status"><b><?= e($t) ?></b><p><?= $txt ?></p></div></section>
<?php endif; ?>

<section class="pred-hero-2 card">
    <?= $side($nameA, 'a', $p, $kA, $snapA) ?>
    <?= $side($nameB, 'b', $p !== null ? 1 - $p : null, $kB, $snapB ?? null) ?>
</section>

<section class="card">
    <div class="card-head"><h2>Win chance, model <?= e($v) ?></h2></div>
    <p class="legend-row">Bars: the model's chance for each fighter. Dotted line: that fighter's Kalshi YES price.</p>
    <div class="chart-box h-320"><canvas data-chart="prediction" data-source="d-pred-<?= e($v) ?>" data-empty="No model or Kalshi number for this fight yet."></canvas></div>
    <?= json_script('d-pred-' . $v, $hasChart ? ['bars' => $bars] : null) ?>
</section>

<?php if ($res['ok']):
    $a = $res['a'];
    $b = $res['b'];
    $parts = $res['parts'];
    $vscale = (float) FightModel::VERSIONS[$v]['params']['scale'];
    $labels = [];
    foreach ($parts as $k => $pt) {
        $labels[$k] = $k === 'context' && !isset($parts['age']) ? 'Context (age, reach, layoff, division)' : (FightModel::PART_LABELS[$k] ?? $k);
    }
?>
<div class="grid grid-2">
    <section class="card">
        <div class="card-head"><h2>Why</h2><span class="muted small">positive = favors <?= e($a['name']) ?></span></div>
        <table class="table pred-parts">
            <thead><tr><th>Part</th><th class="num">Score</th><th class="num">Weight</th><th class="num">Effect</th></tr></thead>
            <tbody>
            <?php foreach ($labels as $k => $label): $val = (float) $parts[$k]['value']; $eff = $val * $parts[$k]['weight']; ?>
                <tr>
                    <td><?= e($label) ?>
                        <div class="delta-bar"><span class="<?= $val >= 0 ? 'pos' : 'neg' ?>" style="width:<?= min(50, abs($val) * 16) ?>%"></span></div></td>
                    <td class="num"><?= e(sprintf('%+.2f', $val)) ?></td>
                    <td class="num"><?= e((string) $parts[$k]['weight']) ?></td>
                    <td class="num"><b><?= e(sprintf('%+.2f', $eff * $vscale)) ?></b></td>
                </tr>
            <?php endforeach; ?>
            <tr><td><b>Total</b> <span class="muted small">(log-odds: +1 ≈ 73%, +2 ≈ 88%)</span></td><td></td><td></td><td class="num"><b><?= e(sprintf('%+.2f', $res['delta'] * $vscale)) ?></b></td></tr>
            </tbody>
        </table>
        <?php if (!empty($parts['performance']['stats'])): ?>
            <h3 class="sub-h">Performance detail</h3>
            <ul class="pred-stats">
            <?php foreach ($parts['performance']['stats'] as $k => $z): ?>
                <li><span><?= e(FightModel::STAT_LABELS[$k] ?? $k) ?></span><b class="<?= $z >= 0 ? 'up' : 'down' ?>"><?= e(sprintf('%+.2f', $z)) ?></b></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php foreach ($res['notes'] as $n): ?><p class="news-note"><?= e($n) ?></p><?php endforeach; ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>Fighters</h2><span class="muted small">from ESPN</span></div>
        <?php
        $fmtPct = fn($v) => $v === null ? '—' : number_format($v * 100, 0) . '%';
        $fmtNum = fn($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d);
        $rowsCmp = [
            'Record'                  => [$a['record'], $b['record']],
            'Fights on ESPN (UFC / other)' => ["{$a['ufc_fights']} / {$a['other_fights']}", "{$b['ufc_fights']} / {$b['other_fights']}"],
            'Age'                     => [$a['age'] ?? '—', $b['age'] ?? '—'],
            'Reach'                   => [$a['reach_in'] ? rtrim(rtrim(number_format($a['reach_in'], 1), '0'), '.') . '"' : '—', $b['reach_in'] ? rtrim(rtrim(number_format($b['reach_in'], 1), '0'), '.') . '"' : '—'],
            'Stance'                  => [$a['stance'] ?? '—', $b['stance'] ?? '—'],
            'Style (from stats)'      => [$a['style'] ?? '—', $b['style'] ?? '—'],
            'Resume score'            => [$fmtNum($a['resume']), $fmtNum($b['resume'])],
            'Elo rating'              => isset($a['rating']) ? [number_format($a['rating']), number_format($b['rating'])] : null,
            'Sig. strikes landed − absorbed / min' => [$fmtNum($a['raw_perf']['sig_diff'] ?? null), $fmtNum($b['raw_perf']['sig_diff'] ?? null)],
            'Striking defense'        => [$fmtPct($a['raw_perf']['str_def'] ?? null), $fmtPct($b['raw_perf']['str_def'] ?? null)],
            'Takedowns / 15 min'      => [$fmtNum($a['raw_perf']['td15'] ?? null), $fmtNum($b['raw_perf']['td15'] ?? null)],
            'Takedown defense'        => [$fmtPct($a['raw_perf']['td_def'] ?? null), $fmtPct($b['raw_perf']['td_def'] ?? null)],
            'Control time'            => [$fmtPct($a['raw_perf']['ctrl'] ?? null), $fmtPct($b['raw_perf']['ctrl'] ?? null)],
            'Knockdowns / 15 min'     => [$fmtNum($a['raw_perf']['kd15'] ?? null), $fmtNum($b['raw_perf']['kd15'] ?? null)],
            'Sub attempts / 15 min'   => [$fmtNum($a['raw_perf']['sub15'] ?? null), $fmtNum($b['raw_perf']['sub15'] ?? null)],
            'Fights with stats used'  => [$a['perf_fights'] . ' of ' . $a['fights_with_stats'], $b['perf_fights'] . ' of ' . $b['fights_with_stats']],
            'Months since last fight' => [$a['layoff_months'] ?? '—', $b['layoff_months'] ?? '—'],
        ];
        ?>
        <table class="table fight-cmp">
            <thead><tr><th></th><th class="num"><?= e($a['name']) ?></th><th class="num"><?= e($b['name']) ?></th></tr></thead>
            <tbody>
            <?php foreach (array_filter($rowsCmp) as $label => [$x, $y]): ?>
                <tr><td class="muted"><?= e($label) ?></td><td class="num"><?= e((string) $x) ?></td><td class="num"><?= e((string) $y) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<div class="grid grid-2">
<?php foreach ([$a, $b] as $f): ?>
    <section class="card">
        <div class="card-head"><h2>Resume · <?= e($f['name']) ?></h2><span class="muted small">score <?= e(number_format($f['resume'], 2)) ?></span></div>
        <div class="table-wrap">
        <table class="table resume-table">
            <thead><tr><th>Date</th><th>Opponent</th><th class="num" title="Opponent's record on the day of the fight">Their record</th><th>Result</th><th class="num">Points</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($f['resume_rows'], 0, 12) as $r): ?>
                <tr>
                    <td class="nowrap small"><?= e(fmt_time($r['date'], 'M Y')) ?></td>
                    <td><?= e($r['opponent']) ?>
                        <?php if ($r['title']): ?><span class="src-chip src-title">title</span><?php endif; ?>
                        <?php if ($r['league'] !== 'ufc'): ?><span class="src-chip"><?= e(strtoupper((string) $r['league'])) ?></span><?php endif; ?></td>
                    <td class="num<?= $r['opp_record_exact'] ? '' : ' muted' ?>" title="<?= $r['opp_record_exact'] ? 'Record on the day' : 'Current record (record on the day not known yet)' ?>"><?= e($r['opp_record']) ?><?= $r['opp_record_exact'] ? '' : '*' ?></td>
                    <td><span class="res res-<?= e(strtolower($r['result'])) ?>"><?= e($r['result']) ?></span> <span class="muted small"><?= e((string) $r['method']) ?></span></td>
                    <td class="num <?= $r['points'] >= 0 ? 'up' : 'down' ?>"><?= e(sprintf('%+.2f', $r['points'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (count($f['resume_rows']) > 12): ?><p class="hint">Showing the 12 most recent of <?= count($f['resume_rows']) ?> fights; all of them count.</p><?php endif; ?>
    </section>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div>
<?php endforeach; ?>

<p class="news-note">A personal research model built from ESPN data. It is not betting advice, and its accuracy hasn't been measured yet.</p>
<?php page_footer();
