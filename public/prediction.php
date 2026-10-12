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
    $rows = db()->query(
        "SELECT m.id, m.event_id, m.yes_subtitle, m.market_title, e.event_title, e.event_start_time, mf.fighter_id,
                (SELECT yes_price FROM market_snapshots s WHERE s.market_id = m.id ORDER BY captured_at DESC LIMIT 1) AS kalshi,
                (SELECT win_probability FROM predictions p WHERE p.market_id = m.id ORDER BY created_at DESC LIMIT 1) AS model,
                (SELECT created_at FROM predictions p WHERE p.market_id = m.id ORDER BY created_at DESC LIMIT 1) AS model_at
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
        // The model is one number per fight: take whichever side has one, the other side is 1 − p.
        $f['ma'] = $s0['model'] !== null ? (float) $s0['model'] : ($s1 && $s1['model'] !== null ? 1 - (float) $s1['model'] : null);
        $f['mb'] = $f['ma'] !== null ? 1 - $f['ma'] : null;
        $f['at'] = max((string) $s0['model_at'], (string) ($s1['model_at'] ?? '')) ?: null;
        $f['linked'] = (bool) ($s0['fighter_id'] || ($s1['fighter_id'] ?? null));
    }
    unset($f);

    page_header('Fight prediction', $user, ['nav' => 'prediction']);
    ?>
    <div class="page-head">
        <div>
            <p class="eyebrow">UFC / Personal model</p>
            <h1>Fight prediction</h1>
            <p class="page-sub">The model's chance for each fighter next to the Kalshi price. Research only, not betting advice.</p>
        </div>
    </div>
    <?php if (!$fights): ?>
        <div class="empty card"><?= icon('target') ?><p>No open UFC markets yet. Run a scan first.</p></div>
    <?php else: ?>
    <section class="card">
        <div class="table-wrap">
        <table class="table fights-table">
            <thead><tr><th>Fight</th><th>Fighter</th><th class="num">Model</th><th class="num">Kalshi</th><th class="num">Gap</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($fights as $id => $f): $href = url('/prediction.php', ['event' => $id]); ?>
                <tr class="fight-a">
                    <td rowspan="<?= $f['b'] ? 2 : 1 ?>"><a href="<?= e($href) ?>"><b><?= e($f['a']) ?><?= $f['b'] ? ' vs ' . e($f['b']) : '' ?></b></a>
                        <span class="cell-sub"><?= e($f['event']['event_title']) ?><?= $f['event']['event_start_time'] ? ' · ' . e(fmt_time($f['event']['event_start_time'], 'M j, g:i A')) : '' ?></span></td>
                    <td><span class="dot dot-a"></span><?= e($f['a']) ?></td>
                    <td class="num" data-value="<?= $f['ma'] ?? -1 ?>"><b class="model-a"><?= $pct($f['ma']) ?></b></td>
                    <td class="num" data-value="<?= $f['ka'] ?? -1 ?>"><b class="kalshi-a"><?= $pct($f['ka']) ?></b></td>
                    <td class="num"><?= $gapHtml($f['ma'], $f['ka']) ?></td>
                    <td rowspan="<?= $f['b'] ? 2 : 1 ?>" class="small"><?= $f['at'] ? reltime($f['at']) : '<span class="muted">' . ($f['linked'] ? 'syncing' : 'not matched') . '</span>' ?></td>
                </tr>
                <?php if ($f['b']): ?>
                <tr class="fight-b">
                    <td><span class="dot dot-b"></span><?= e($f['b']) ?></td>
                    <td class="num"><b class="model-b"><?= $pct($f['mb']) ?></b></td>
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

/* ---------- chart: both fighters, Kalshi (snapshots) and model (saved predictions) ---------- */
$ranges = ['24h' => '-24 hours', '7d' => '-7 days', '30d' => '-30 days', 'all' => null];
$range  = array_key_exists($_GET['range'] ?? '', $ranges) ? $_GET['range'] : '7d';
$now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$from   = $ranges[$range] ? $now->modify($ranges[$range]) : new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC'));

$kalshiSeries = function (int $id) use ($from): array {
    $q = db()->prepare('SELECT captured_at, yes_price FROM market_snapshots
                         WHERE market_id = :m AND captured_at >= :from AND yes_price IS NOT NULL ORDER BY captured_at');
    $q->execute([':m' => $id, ':from' => $from->format('c')]);
    $rows  = $q->fetchAll();
    $every = max(1, (int) ceil(count($rows) / 600));   // ≤ ~600 points per line
    $out   = [];
    foreach ($rows as $i => $r) {
        if ($i % $every === 0 || $i === count($rows) - 1) {
            $out[] = ['x' => iso($r['captured_at']), 'y' => round((float) $r['yes_price'] * 100, 2)];
        }
    }
    return $out;
};
$modelSeries = function (int $id, ?float $live, ?string $startX) use ($from, $now): array {
    $q = db()->prepare('SELECT created_at, win_probability FROM predictions
                         WHERE market_id = :m AND model_version = :v AND created_at >= :from ORDER BY created_at');
    $q->execute([':m' => $id, ':v' => FightModel::VERSION, ':from' => $from->format('c')]);
    $out = array_map(fn($r) => ['x' => iso($r['created_at']), 'y' => round((float) $r['win_probability'] * 100, 2)], $q->fetchAll());
    // Start at the edge of the range with the value the model had then.
    $q = db()->prepare('SELECT win_probability FROM predictions WHERE market_id = :m AND model_version = :v AND created_at < :from
                         ORDER BY created_at DESC LIMIT 1');
    $q->execute([':m' => $id, ':v' => FightModel::VERSION, ':from' => $from->format('c')]);
    if (($before = $q->fetchColumn()) !== false && $before !== null) {
        array_unshift($out, ['x' => $startX ?? iso($from->format('c')), 'y' => round((float) $before * 100, 2)]);
    }
    // Points are only saved when the number moves; carry the live value to now.
    if ($live !== null) {
        $out[] = ['x' => iso($now->format('c')), 'y' => round($live * 100, 2)];
    }
    return $out;
};
$flip = fn(array $series) => array_map(fn($pt) => ['x' => $pt['x'], 'y' => round(100 - $pt['y'], 2)], $series);

$kSeriesA = $kalshiSeries($aId);
$kSeriesB = $bId !== null ? $kalshiSeries($bId) : $flip($kSeriesA);
$mSeriesA = $modelSeries($aId, $p, $kSeriesA[0]['x'] ?? null);
$mSeriesB = $flip($mSeriesA);   // the model's two chances always add up to 100%

// Fighter A: green Kalshi / blue model. Fighter B: sand Kalshi / violet model. Kalshi = solid line, model = stepped + dots.
$lines = [
    ['label' => "$nameA · Kalshi", 'kind' => 'kalshi', 'color' => '#7ccba6', 'data' => $kSeriesA],
    ['label' => "$nameA · Model",  'kind' => 'model',  'color' => '#8fb4d6', 'data' => $mSeriesA],
    ['label' => "$nameB · Kalshi", 'kind' => 'kalshi', 'color' => '#d9b382', 'data' => $kSeriesB],
    ['label' => "$nameB · Model",  'kind' => 'model',  'color' => '#b4a7d6', 'data' => $mSeriesB],
];
$hasChart = (bool) array_filter($lines, fn($l) => $l['data']);

log_activity('market_view', null, $aId, ['source' => 'prediction', 'range' => $range]);
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
        <p class="eyebrow">Personal model · <?= e(FightModel::VERSION) ?></p>
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

<?php if (!$res['ok']): [$t, $txt] = $reasonText[$res['reason']] ?? ['Prediction unavailable', e($res['reason'])]; ?>
    <section class="card"><div class="news-status"><b><?= e($t) ?></b><p><?= $txt ?></p></div></section>
<?php endif; ?>

<section class="pred-hero-2 card">
    <?= $side($nameA, 'a', $p, $kA, $snapA) ?>
    <?= $side($nameB, 'b', $p !== null ? 1 - $p : null, $kB, $snapB ?? null) ?>
</section>

<section class="card">
    <div class="card-head wrap">
        <h2>Win chance over time</h2>
        <div class="range">
            <?php foreach (array_keys($ranges) as $key): ?>
                <a class="pill<?= $range === $key ? ' active' : '' ?>" href="<?= e(url('/prediction.php', ['event' => $eventId, 'market' => $aId, 'range' => $key])) ?>"><?= e($key) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="legend-row">
        <?php foreach ($lines as $l): ?>
            <span><i class="swatch swatch-<?= e($l['kind']) ?>" style="--c:<?= e($l['color']) ?>"></i><?= e($l['label']) ?></span>
        <?php endforeach; ?>
        <span><i class="swatch swatch-even"></i>50%</span>
    </div>
    <div class="chart-box h-320"><canvas data-chart="prediction" data-source="d-pred" data-empty="No price or prediction history in this period yet."></canvas></div>
    <?= json_script('d-pred', $hasChart ? ['lines' => $lines] : null) ?>
    <p class="hint">Solid lines are Kalshi's YES price for each fighter; stepped lines are the model. The model saves a point whenever its number moves by 0.5 pp or more (and at least every 6 hours).</p>
</section>

<?php if ($res['ok']):
    $a = $res['a'];
    $b = $res['b'];
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
            'Fights on ESPN (UFC / other)' => ["{$a['ufc_fights']} / {$a['other_fights']}", "{$b['ufc_fights']} / {$b['other_fights']}"],
            'Age'                     => [$a['age'] ?? '—', $b['age'] ?? '—'],
            'Reach'                   => [$a['reach_in'] ? rtrim(rtrim(number_format($a['reach_in'], 1), '0'), '.') . '"' : '—', $b['reach_in'] ? rtrim(rtrim(number_format($b['reach_in'], 1), '0'), '.') . '"' : '—'],
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
            'Fights with stats used'  => [$a['perf_fights'] . ' of ' . $a['fights_with_stats'], $b['perf_fights'] . ' of ' . $b['fights_with_stats']],
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
