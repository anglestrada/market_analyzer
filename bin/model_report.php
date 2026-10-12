<?php
/**
 * Model report: how the fight model did against real results (and against Kalshi's pre-fight price).
 *
 *   php bin/model_report.php                  score new finished fights, backtest the current version, print the report
 *   php bin/model_report.php --version=v1     only one model version
 *   php bin/model_report.php --source=live    only predictions the app actually made before the fight
 *   php bin/model_report.php --source=history only the past-UFC-fights backtest
 *   php bin/model_report.php --history        also score every version on stored past UFC fights
 *                                             (needs `php bin/fighters_sync.php --deep` first)
 *   php bin/model_report.php --redo-backtest  recompute backtests (after adding a version)
 *   php bin/model_report.php --fights=60      how many fights to list in the paste block (default 40)
 *
 * The last section ("PASTE THIS") is a compact copy of everything to send back for tuning the formula.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';

$opt = ['version' => null, 'source' => null, 'redo-backtest' => false, 'history' => false, 'fights' => 40];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(version|source)=([\w.-]+)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    } elseif (preg_match('/^--fights=(\d+)$/', $a, $m)) {
        $opt['fights'] = (int) $m[1];
    } elseif ($a === '--redo-backtest') {
        $opt['redo-backtest'] = true;
    } elseif ($a === '--history') {
        $opt['history'] = true;
    } else {
        fwrite(STDERR, "Unknown option: $a\n");
        exit(1);
    }
}

$say = fn(string $m) => print("$m\n");
$ev  = ModelEvaluator::make(new KalshiClient());
$ev->resolve($say);
$ev->backtest($say, $opt['redo-backtest']);
if ($opt['history']) {
    $ev->history($say, $opt['redo-backtest']);
}
$r = $ev->report($opt['version'], $opt['source']);

$pct = fn(?float $v, int $d = 1) => $v === null ? '—' : number_format($v * 100, $d) . '%';
$num = fn(?float $v, int $d = 3) => $v === null ? '—' : number_format($v, $d);

if (!$r['scorecard']) {
    echo "\nNo finished fights with a prediction yet.\n"
       . "Results appear once a linked Kalshi market closes and the result is known (Kalshi settlement or ESPN final).\n";
    exit(0);
}

echo "\n=== SCORECARD (lower Brier / log loss is better; 0.25 Brier = always saying 50%) ===\n";
printf("%-16s %6s %9s %8s %8s | %9s %8s %8s | %s\n", 'Version · source', 'Fights', 'Accuracy', 'Brier', 'LogLoss', 'Kalshi acc', 'Brier', 'LogLoss', 'Skill vs Kalshi');
foreach ($r['scorecard'] as $k => $s) {
    printf("%-16s %6d %9s %8s %8s | %9s %8s %8s | %s\n", $k, $s['fights'], $pct($s['model_accuracy']), $num($s['model_brier']),
        $num($s['model_logloss']), $pct($s['kalshi_accuracy']), $num($s['kalshi_brier']), $num($s['kalshi_logloss']),
        $s['skill_vs_kalshi'] === null ? '—' : sprintf('%+.3f (%d fights)', $s['skill_vs_kalshi'], $s['with_kalshi']));
}

echo "\n=== CALIBRATION (model's favourite: predicted vs. how often they actually won) ===\n";
foreach ($r['calibration'] as $k => $rows) {
    echo "$k\n";
    foreach ($rows as $c) {
        printf("  %-8s %4d fights   predicted %6s   won %6s\n", $c['range'], $c['fights'], $pct($c['predicted']), $pct($c['actual']));
    }
}

echo "\n=== PARTS OF THE FORMULA (did each part point at the winner?) ===\n";
foreach ($r['parts'] as $k => $parts) {
    echo "$k\n";
    foreach ($parts as $name => $p) {
        if ($p['fights_used'] === 0) {
            continue;   // this version doesn't use that part
        }
        printf("  %-12s used in %3d fights   picked winner %6s   avg size %s   correlation %s\n",
            $name, $p['fights_used'], $pct($p['picked_winner']), $num($p['avg_size'], 2), $num($p['corr_with_result'], 2));
    }
}

echo "\n=== MODEL vs KALSHI (fights where they disagreed by 5 pp or more) ===\n";
foreach ($r['disagreements'] as $k => $d) {
    printf("  %-16s %3d fights   model was closer to the result in %s\n", $k, $d['fights'], $pct($d['model_closer']));
}

echo "\n=== PASTE THIS (everything above + the latest {$opt['fights']} fights) ===\n";
$fights = array_map(fn($f) => [
    'date' => substr((string) $f['cutoff_at'], 0, 10), 'v' => $f['model_version'], 'src' => $f['source'],
    'a' => $f['a_name'], 'b' => $f['b_name'], 'model' => (float) $f['model_p'],
    'kalshi' => $f['kalshi_p'] !== null ? (float) $f['kalshi_p'] : null, 'a_won' => (int) $f['outcome'],
    'method' => $f['method'], 'rnd' => $f['end_round'] !== null ? (int) $f['end_round'] : null,
    'parts' => array_filter([
        'resume' => $f['resume_score'] !== null ? (float) $f['resume_score'] : null,
        'rating' => $f['rating_score'] !== null ? (float) $f['rating_score'] : null,
        'age' => $f['age_score'] !== null ? (float) $f['age_score'] : null,
        'perf' => $f['performance_score'] !== null ? (float) $f['performance_score'] : null,
        'matchup' => $f['matchup_score'] !== null ? (float) $f['matchup_score'] : null,
        'context' => $f['context_score'] !== null ? (float) $f['context_score'] : null,
    ], fn($v) => $v !== null),
    'data' => (function () use ($f) {
        $in = $f['inputs'] ? json_decode((string) $f['inputs'], true) : null;
        if (!is_array($in) || !isset($in['a'], $in['b'])) {
            return null;
        }
        $pick = fn(array $x) => array_intersect_key($x, array_flip(['ufc_fights', 'other_fights', 'perf_fights', 'approx_records', 'age', 'style']));
        return ['a' => $pick($in['a']), 'b' => $pick($in['b']), 'flipped' => !empty($in['flipped'])];
    })(),
], array_slice($r['fights'], 0, $opt['fights']));

echo json_encode([
    'models' => array_map(fn(FightModel $m) => ['version' => $m->version, 'params_hash' => $m->paramsHash(),
        'description' => FightModel::VERSIONS[$m->version]['description'], 'params' => $m->params], FightModel::all(db())),
    'scorecard' => $r['scorecard'], 'calibration' => $r['calibration'], 'parts' => $r['parts'],
    'disagreements' => $r['disagreements'], 'fights' => $fights,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
echo "=== END ===\n";
