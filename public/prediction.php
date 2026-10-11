<?php
/**
 * Fight prediction.
 *   /prediction.php             every open market: model vs Kalshi
 *   /prediction.php?market=ID   one fight: chart (model vs Kalshi over time), breakdown, fighter comparison, resume
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user     = require_login();
$marketId = (int) ($_GET['market'] ?? 0);
$model    = new FightModel(db());
$pct      = fn(?float $p, int $d = 0) => $p === null ? '—' : number_format($p * 100, $d) . '%';
$gapHtml  = function (?float $model, ?float $kalshi): string {
    if ($model === null || $kalshi === null) {
        return '<span class="muted">—</span>';
    }
    return fmt_pp(($model - $kalshi) * 100);
};

/* =====================================================================
 * List of open markets
 * ================================================================== */
if ($marketId <= 0) {
    $rows = db()->query(
        "SELECT m.id, m.yes_subtitle, m.market_title, e.event_title, e.event_start_time, mf.fighter_id,
                o.full_name AS opponent,
                (SELECT yes_price FROM market_snapshots s WHERE s.market_id = m.id ORDER BY captured_at DESC LIMIT 1) AS kalshi,
                (SELECT win_probability FROM predictions p WHERE p.market_id = m.id ORDER BY created_at DESC LIMIT 1) AS model,
                (SELECT created_at FROM predictions p WHERE p.market_id = m.id ORDER BY created_at DESC LIMIT 1) AS model_at
           FROM markets m
           JOIN events e ON e.id = m.event_id
      LEFT JOIN market_fighters mf ON mf.market_id = m.id
      LEFT JOIN fighters o ON o.id = mf.opponent_id
          WHERE e.sport = 'UFC' AND m.status = 'open'
       ORDER BY e.event_start_time ASC NULLS LAST, e.id, m.yes_subtitle"
    )->fetchAll();

    page_header('Fight prediction', $user, ['nav' => 'prediction']);
    ?>
    <div class="page-head">
        <div>
            <p class="eyebrow">UFC / Personal model</p>
            <h1>Fight prediction</h1>
            <p class="page-sub">The model's chance for each fighter next to the Kalshi price. Research only, not betting advice.</p>
        </div>
    </div>
    <?php if (!$rows): ?>
        <div class="empty card"><?= icon('target') ?><p>No open UFC markets yet. Run a scan first.</p></div>
    <?php else: ?>
    <section class="card">
        <div class="table-wrap">
        <table class="table sortable">
            <thead><tr><th>Fighter</th><th>Fight</th><th class="num">Model</th><th class="num">Kalshi</th><th class="num">Gap</th><th>Updated</th><th data-nosort></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $mp = $r['model'] !== null ? (float) $r['model'] : null;
                $kp = $r['kalshi'] !== null ? (float) $r['kalshi'] : null; ?>
                <tr>
                    <td><a href="<?= e(url('/prediction.php', ['market' => $r['id']])) ?>"><b><?= e($r['yes_subtitle'] ?: $r['market_title']) ?></b></a>
                        <?php if ($r['opponent']): ?><span class="cell-sub">vs <?= e($r['opponent']) ?></span><?php endif; ?></td>
                    <td><?= e($r['event_title']) ?><span class="cell-sub"><?= $r['event_start_time'] ? e(fmt_time($r['event_start_time'], 'M j, g:i A')) : '' ?></span></td>
                    <td class="num" data-value="<?= $mp ?? -1 ?>"><b class="model-text"><?= $pct($mp) ?></b></td>
                    <td class="num" data-value="<?= $kp ?? -1 ?>"><b class="yes-text"><?= $pct($kp) ?></b></td>
                    <td class="num" data-value="<?= $mp !== null && $kp !== null ? $mp - $kp : 0 ?>"><?= $gapHtml($mp, $kp) ?></td>
                    <td class="small"><?= $r['model_at'] ? reltime($r['model_at']) : '<span class="muted">' . ($r['fighter_id'] ? 'syncing' : 'not matched') . '</span>' ?></td>
                    <td class="nowrap"><a class="pill" href="<?= e(url('/market.php', ['id' => $r['id']])) ?>">Market</a></td>
                </tr>
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
 * One market
 * ================================================================== */
$stmt = db()->prepare('SELECT m.*, e.event_title, e.event_start_time FROM markets m JOIN events e ON e.id = m.event_id WHERE m.id = :id');
$stmt->execute([':id' => $marketId]);
$market = $stmt->fetch();
if (!$market) {
    http_response_code(404);
    page_header('Not found', $user, ['nav' => 'prediction']);
    echo '<div class="empty card">' . icon('search') . '<p>Market not found.</p></div>';
    page_footer();
    exit;
}

$res  = $model->predictMarket($marketId);
$name = $market['yes_subtitle'] ?: $market['market_title'];

// Latest Kalshi price
$q = db()->prepare('SELECT yes_price, captured_at FROM market_snapshots WHERE market_id = :m ORDER BY captured_at DESC LIMIT 1');
$q->execute([':m' => $marketId]);
$snap   = $q->fetch() ?: null;
$kalshi = $snap && $snap['yes_price'] !== null ? (float) $snap['yes_price'] : null;
$p      = $res['ok'] ? (float) $res['p'] : null;

/* ---------- chart: Kalshi YES (from snapshots) and the model (from saved predictions) ---------- */
$ranges = ['24h' => '-24 hours', '7d' => '-7 days', '30d' => '-30 days', 'all' => null];
$range  = array_key_exists($_GET['range'] ?? '', $ranges) ? $_GET['range'] : '7d';
$now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$from   = $ranges[$range] ? $now->modify($ranges[$range]) : new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC'));

$q = db()->prepare('SELECT captured_at, yes_price FROM market_snapshots
                     WHERE market_id = :m AND captured_at >= :from AND yes_price IS NOT NULL ORDER BY captured_at');
$q->execute([':m' => $marketId, ':from' => $from->format('c')]);
$snaps  = $q->fetchAll();
$every  = max(1, (int) ceil(count($snaps) / 600));   // ≤ ~600 points
$kSeries = [];
foreach ($snaps as $i => $s) {
    if ($i % $every === 0 || $i === count($snaps) - 1) {
        $kSeries[] = ['x' => iso($s['captured_at']), 'y' => round((float) $s['yes_price'] * 100, 2)];
    }
}
$q = db()->prepare('SELECT created_at, win_probability FROM predictions
                     WHERE market_id = :m AND model_version = :v AND created_at >= :from ORDER BY created_at');
$q->execute([':m' => $marketId, ':v' => FightModel::VERSION, ':from' => $from->format('c')]);
$mSeries = array_map(fn($r) => ['x' => iso($r['created_at']), 'y' => round((float) $r['win_probability'] * 100, 2)], $q->fetchAll());
// Start the model line at the edge of the range with the value it had then.
$q = db()->prepare('SELECT win_probability FROM predictions WHERE market_id = :m AND model_version = :v AND created_at < :from
                     ORDER BY created_at DESC LIMIT 1');
$q->execute([':m' => $marketId, ':v' => FightModel::VERSION, ':from' => $from->format('c')]);
if (($before = $q->fetchColumn()) !== false && $before !== null) {
    $start = $kSeries[0]['x'] ?? iso($from->format('c'));
    array_unshift($mSeries, ['x' => $start, 'y' => round((float) $before * 100, 2)]);
}
// The model only stores a point when it changes; carry the latest value to now (or show the live value).
if ($p !== null) {
    $mSeries[] = ['x' => iso($now->format('c')), 'y' => round($p * 100, 2)];
}

$opponentName = $res['b']['name'] ?? ($market['no_subtitle'] ?: 'Opponent');
$chart = [
    'kalshi' => $kSeries,
    'model'  => $mSeries,
    'label'  => $name,
];

log_activity('market_view', null, $marketId, ['source' => 'prediction', 'range' => $range]);
page_header('Prediction · ' . $name, $user, ['nav' => 'prediction']);

$reasonText = [
    'not_linked'  => ['Not matched to ESPN yet', "This Kalshi market hasn't been matched to an ESPN fighter. Matching runs with every scan; run <code>php bin/fighters_sync.php --market=$marketId</code> to do it now."],
    'no_opponent' => ['Opponent not matched', "The opponent couldn't be found on ESPN. Run <code>php bin/fighters_sync.php --market=$marketId --relink</code>, or set <code>market_fighters.opponent_id</code> by hand (and <code>match_method = 'manual'</code>)."],
    'not_synced'  => ['Fighter data still syncing', "ESPN fighter data fills in a little every scan. Run <code>php bin/fighters_sync.php --market=$marketId</code> to finish it now."],
    'no_history'  => ['No fight history on ESPN', 'One of the fighters has no completed fights on ESPN, so the model has nothing to score.'],
];
?>
<nav class="crumbs"><a href="<?= e(url('/prediction.php')) ?>">Fight prediction</a><span>›</span><?= e($market['event_title']) ?></nav>

<div class="page-head">
    <div>
        <p class="eyebrow">Personal model · <?= e(FightModel::VERSION) ?></p>
        <h1><?= e($name) ?> <span class="muted">vs</span> <?= e($opponentName) ?></h1>
        <div class="meta">
            <?= badge($market['status']) ?>
            <?php if ($market['event_start_time']): ?><span class="muted"><?= e(fmt_time($market['event_start_time'], 'D, M j · g:i A')) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="sel-actions">
        <a class="pill" href="<?= e(url('/market.php', ['id' => $marketId])) ?>">Market details</a>
        <a class="pill" href="<?= e(url('/index.php', ['market' => $marketId])) ?>">Overview</a>
    </div>
</div>

<?php if (!$res['ok']): [$t, $txt] = $reasonText[$res['reason']] ?? ['Prediction unavailable', e($res['reason'])]; ?>
    <section class="card"><div class="news-status"><b><?= e($t) ?></b><p><?= $txt ?></p></div></section>
<?php else: $a = $res['a']; $b = $res['b']; ?>

<section class="pred-hero card">
    <div class="pred-side">
        <span class="pred-label">Model · <?= e($a['name']) ?></span>
        <b class="pred-big model-text"><?= $pct($p, 1) ?></b>
        <span class="muted small"><?= e($b['name']) ?> <?= $pct(1 - $p, 1) ?></span>
    </div>
    <div class="pred-side">
        <span class="pred-label">Kalshi · YES</span>
        <b class="pred-big yes-text"><?= $pct($kalshi, 1) ?></b>
        <span class="muted small"><?= $snap ? 'price ' . reltime($snap['captured_at']) : 'no snapshot yet' ?></span>
    </div>
    <div class="pred-side">
        <span class="pred-label">Gap (model − Kalshi)</span>
        <b class="pred-big"><?= $gapHtml($p, $kalshi) ?></b>
        <span class="muted small"><?= $p !== null && $kalshi !== null ? ($p > $kalshi ? 'model likes ' . e($a['name']) . ' more' : ($p < $kalshi ? 'Kalshi likes ' . e($a['name']) . ' more' : 'they agree')) : '' ?></span>
    </div>
</section>

<?php endif; ?>

<section class="card">
    <div class="card-head wrap">
        <h2>Win chance over time · <?= e($name) ?></h2>
        <div class="range">
            <?php foreach (array_keys($ranges) as $key): ?>
                <a class="pill<?= $range === $key ? ' active' : '' ?>" href="<?= e(url('/prediction.php', ['market' => $marketId, 'range' => $key])) ?>"><?= e($key) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="legend-row">
        <span><i class="swatch swatch-kalshi"></i>Kalshi YES price</span>
        <span><i class="swatch swatch-model"></i>Model</span>
        <span><i class="swatch swatch-even"></i>50%</span>
    </div>
    <div class="chart-box h-320"><canvas data-chart="prediction" data-source="d-pred" data-empty="No price or prediction history in this period yet."></canvas></div>
    <?= json_script('d-pred', ($kSeries || $mSeries) ? $chart : null) ?>
    <p class="hint">The model saves a point whenever its number moves by 0.5 pp or more (and at least every 6 hours).</p>
</section>

<?php if ($res['ok']):
    $parts = $res['parts'];
    $labels = ['resume' => 'Resume (who they beat, how, how recently)', 'performance' => 'Performance (fight stats)',
               'matchup' => 'Style matchup (offense vs. the other\'s defense)', 'context' => 'Context (age, reach, layoff, division)'];
?>
<div class="grid grid-2">
    <section class="card">
        <div class="card-head"><h2>Why</h2><span class="muted small">positive = favors <?= e($a['name']) ?></span></div>
        <table class="table pred-parts">
            <thead><tr><th>Part</th><th class="num">Score</th><th class="num">Weight</th><th class="num">Effect</th></tr></thead>
            <tbody>
            <?php foreach ($labels as $k => $label): $v = (float) $parts[$k]['value']; $eff = $v * $parts[$k]['weight']; ?>
                <tr>
                    <td><?= e($label) ?>
                        <div class="delta-bar"><span class="<?= $v >= 0 ? 'pos' : 'neg' ?>" style="width:<?= min(50, abs($v) * 16) ?>%"></span></div></td>
                    <td class="num"><?= e(sprintf('%+.2f', $v)) ?></td>
                    <td class="num"><?= e((string) $parts[$k]['weight']) ?></td>
                    <td class="num"><b><?= e(sprintf('%+.2f', $eff)) ?></b></td>
                </tr>
            <?php endforeach; ?>
            <tr><td><b>Total (Δ)</b></td><td></td><td></td><td class="num"><b><?= e(sprintf('%+.2f', $res['delta'])) ?></b></td></tr>
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
            'Age'                     => [$a['age'] ?? '—', $b['age'] ?? '—'],
            'Reach'                   => [$a['reach_in'] ? $a['reach_in'] . '"' : '—', $b['reach_in'] ? $b['reach_in'] . '"' : '—'],
            'Stance'                  => [$a['stance'] ?? '—', $b['stance'] ?? '—'],
            'Style (from stats)'      => [$a['style'] ?? '—', $b['style'] ?? '—'],
            'Resume score'            => [$fmtNum($a['resume']), $fmtNum($b['resume'])],
            'Sig. strikes landed − absorbed / min' => [$fmtNum($a['perf']['sig_diff'] ?? null), $fmtNum($b['perf']['sig_diff'] ?? null)],
            'Striking defense'        => [$fmtPct($a['perf']['str_def'] ?? null), $fmtPct($b['perf']['str_def'] ?? null)],
            'Takedowns / 15 min'      => [$fmtNum($a['perf']['td15'] ?? null), $fmtNum($b['perf']['td15'] ?? null)],
            'Takedown defense'        => [$fmtPct($a['perf']['td_def'] ?? null), $fmtPct($b['perf']['td_def'] ?? null)],
            'Control time'            => [$fmtPct($a['perf']['ctrl'] ?? null), $fmtPct($b['perf']['ctrl'] ?? null)],
            'Knockdowns / 15 min'     => [$fmtNum($a['perf']['kd15'] ?? null), $fmtNum($b['perf']['kd15'] ?? null)],
            'Sub attempts / 15 min'   => [$fmtNum($a['perf']['sub15'] ?? null), $fmtNum($b['perf']['sub15'] ?? null)],
            'Fights with stats used'  => [$a['perf_fights'], $b['perf_fights']],
            'Months since last fight' => [$a['layoff_months'] ?? '—', $b['layoff_months'] ?? '—'],
        ];
        ?>
        <table class="table fight-cmp">
            <thead><tr><th></th><th class="num"><?= e($a['name']) ?></th><th class="num"><?= e($b['name']) ?></th></tr></thead>
            <tbody>
            <?php foreach ($rowsCmp as $label => [$x, $y]): ?>
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

<p class="news-note">A personal research model built from ESPN data. It is not betting advice, and its accuracy hasn't been measured yet.</p>
<?php page_footer();
