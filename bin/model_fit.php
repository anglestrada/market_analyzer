<?php
/**
 * Fit a model version's part weights to real results (logistic regression), to design the next version.
 *
 *   php bin/model_fit.php                   fit the newest version on every scored fight (history + backtest + live)
 *   php bin/model_fit.php --version=v1      fit another version's parts
 *   php bin/model_fit.php --source=history  only one source
 *
 * Prints the current weights next to the fitted ones and how much the fit would improve Brier / log loss,
 * measured on fights the fit didn't see (5-fold cross-validation). Nothing is changed: copy the weights you
 * trust into a new version in FightModel::VERSIONS.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';

$versions = FightModel::versions();
$opt = ['version' => end($versions), 'source' => null];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(version|source)=([\w.-]+)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "Unknown option: $a\n");
        exit(1);
    }
}
$model = new FightModel(db(), $opt['version']);
$cols  = ['resume' => 'resume_score', 'rating' => 'rating_score', 'performance' => 'performance_score',
          'matchup' => 'matchup_score', 'context' => 'context_score', 'age' => 'age_score'];
$parts = array_values(array_intersect(array_keys($model->params['weights']), array_keys($cols)));

$sql = 'SELECT model_p, outcome, ' . implode(', ', array_map(fn($p) => $cols[$p], $parts)) . '
          FROM prediction_results WHERE model_version = :v AND outcome IS NOT NULL'
     . ($opt['source'] ? ' AND source = :s' : '');
$q = db()->prepare($sql);
$q->execute([':v' => $opt['version']] + ($opt['source'] ? [':s' => $opt['source']] : []));
$rows = array_values(array_filter($q->fetchAll(), function ($r) use ($parts, $cols) {
    foreach ($parts as $p) {
        if ($r[$cols[$p]] === null) {
            return false;
        }
    }
    return true;
}));

$n = count($rows);
echo "Model {$opt['version']} · " . ($opt['source'] ?? 'all sources') . " · $n scored fights with every part logged\n";
if ($n < 30) {
    echo "Need at least 30 (ideally 200+) fights to fit weights. Run `php bin/fighters_sync.php --deep` and\n"
       . "`php bin/model_report.php --history` to score past UFC fights, or wait for more cards.\n";
    exit(0);
}

$X = array_map(fn($r) => array_map(fn($p) => (float) $r[$cols[$p]], $parts), $rows);
$y = array_map(fn($r) => (int) $r['outcome'], $rows);

/** Logistic regression without an intercept (fighter A / B order is arbitrary), L2 penalty, Newton's method. */
function fit(array $X, array $y, float $lambda = 1.0): array
{
    $d = count($X[0]);
    $b = array_fill(0, $d, 0.0);
    for ($it = 0; $it < 50; $it++) {
        $g = array_fill(0, $d, 0.0);
        $H = array_fill(0, $d, array_fill(0, $d, 0.0));
        foreach ($X as $i => $x) {
            $z = 0.0;
            foreach ($x as $j => $v) { $z += $b[$j] * $v; }
            $p = 1 / (1 + exp(-$z));
            $w = $p * (1 - $p);
            foreach ($x as $j => $v) {
                $g[$j] += ($p - $y[$i]) * $v;
                foreach ($x as $k => $u) { $H[$j][$k] += $w * $v * $u; }
            }
        }
        for ($j = 0; $j < $d; $j++) { $g[$j] += $lambda * $b[$j]; $H[$j][$j] += $lambda; }
        $step = solve($H, $g);
        $max = 0.0;
        for ($j = 0; $j < $d; $j++) { $b[$j] -= $step[$j]; $max = max($max, abs($step[$j])); }
        if ($max < 1e-7) {
            break;
        }
    }
    return $b;
}

function solve(array $A, array $v): array
{
    $n = count($v);
    for ($i = 0; $i < $n; $i++) { $A[$i][] = $v[$i]; }
    for ($c = 0; $c < $n; $c++) {
        $piv = $c;
        for ($r = $c + 1; $r < $n; $r++) { if (abs($A[$r][$c]) > abs($A[$piv][$c])) { $piv = $r; } }
        [$A[$c], $A[$piv]] = [$A[$piv], $A[$c]];
        if (abs($A[$c][$c]) < 1e-12) { continue; }
        for ($r = 0; $r < $n; $r++) {
            if ($r === $c) { continue; }
            $f = $A[$r][$c] / $A[$c][$c];
            for ($k = $c; $k <= $n; $k++) { $A[$r][$k] -= $f * $A[$c][$k]; }
        }
    }
    return array_map(fn($i) => abs($A[$i][$i]) < 1e-12 ? 0.0 : $A[$i][$n] / $A[$i][$i], range(0, $n - 1));
}

$score = function (array $ps, array $ys): array {
    $b = $l = $c = 0.0;
    foreach ($ps as $i => $p) {
        $p = max(0.001, min(0.999, $p));
        $b += ($p - $ys[$i]) ** 2;
        $l += -log($ys[$i] ? $p : 1 - $p);
        $c += ($p > 0.5) === (bool) $ys[$i] ? 1 : 0;
    }
    $n = count($ps);
    return ['brier' => $b / $n, 'logloss' => $l / $n, 'accuracy' => $c / $n];
};
$predict = fn(array $b, array $x) => 1 / (1 + exp(-array_sum(array_map(fn($w, $v) => $w * $v, $b, $x))));

// Current model, as logged
$current = $score(array_map(fn($r) => (float) $r['model_p'], $rows), $y);

// 5-fold cross-validation of the fit (shuffled with a fixed seed so runs are repeatable)
mt_srand(42);
$idx = range(0, $n - 1);
shuffle($idx);
$cvP = [];
for ($k = 0; $k < 5; $k++) {
    $test  = array_values(array_filter($idx, fn($i, $pos) => $pos % 5 === $k, ARRAY_FILTER_USE_BOTH));
    $train = array_values(array_diff($idx, $test));
    $b = fit(array_map(fn($i) => $X[$i], $train), array_map(fn($i) => $y[$i], $train));
    foreach ($test as $i) {
        $cvP[$i] = $predict($b, $X[$i]);
    }
}
ksort($cvP);
$cv  = $score(array_values($cvP), $y);
$all = fit($X, $y);

printf("\n%-12s %10s %10s\n", 'Part', 'Current', 'Fitted');
foreach ($parts as $j => $p) {
    // current coefficient on the log-odds = scale × weight
    printf("%-12s %10.3f %10.3f\n", $p, $model->params['scale'] * $model->params['weights'][$p], $all[$j]);
}
printf("\n%-24s %8s %8s %9s\n", '', 'Brier', 'LogLoss', 'Accuracy');
printf("%-24s %8.4f %8.4f %8.1f%%\n", 'Current weights', $current['brier'], $current['logloss'], $current['accuracy'] * 100);
printf("%-24s %8.4f %8.4f %8.1f%%\n", 'Fitted (cross-validated)', $cv['brier'], $cv['logloss'], $cv['accuracy'] * 100);
echo "\nA fitted weight near 0 means that part isn't helping; a negative one means it points the wrong way.\n"
   . "The fitted weights are on the log-odds (scale 1): use them with 'scale' => 1.0 in a new version.\n";

echo "\n=== PASTE THIS ===\n" . json_encode([
    'version' => $opt['version'], 'source' => $opt['source'] ?? 'all', 'fights' => $n,
    'current_coefficients' => array_combine($parts, array_map(fn($p) => $model->params['scale'] * $model->params['weights'][$p], $parts)),
    'fitted_coefficients' => array_combine($parts, array_map(fn($v) => round($v, 4), $all)),
    'current' => array_map(fn($v) => round($v, 4), $current), 'fitted_cv' => array_map(fn($v) => round($v, 4), $cv),
], JSON_UNESCAPED_SLASHES) . "\n=== END ===\n";
