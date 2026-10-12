<?php
/**
 * Sync ESPN fighter data for the fight-prediction view, then update predictions.
 * Every scan already does a small slice of this (FIGHTER_SYNC_SCAN_BUDGET); run this to fill everything at once,
 * e.g. the first time or before a card.
 *
 *   php bin/fighters_sync.php                   all open UFC markets (up to 3000 ESPN requests)
 *   php bin/fighters_sync.php --market=123      one Kalshi market (and its opponent)
 *   php bin/fighters_sync.php --budget=500      stop after 500 ESPN requests (the next run continues)
 *   php bin/fighters_sync.php --relink          redo automatic market → fighter matches (manual ones are kept)
 *   php bin/fighters_sync.php --predict-only    no ESPN requests; just recompute predictions
 *   php bin/fighters_sync.php --deep            also every UFC opponent's full history (for bin/model_report.php --history)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';

$opt = ['market' => null, 'budget' => 3000, 'relink' => false, 'predict-only' => false, 'deep' => false];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(market|budget)=(\d+)$/', $a, $m)) {
        $opt[$m[1]] = (int) $m[2];
    } elseif (in_array($a, ['--relink', '--predict-only', '--deep'], true)) {
        $opt[substr($a, 2)] = true;
    } else {
        fwrite(STDERR, "Unknown option: $a\nUsage: php bin/fighters_sync.php [--market=ID] [--budget=N] [--relink] [--predict-only] [--deep]\n");
        exit(1);
    }
}

$say = fn(string $m) => print('[' . gmdate('H:i:s') . "] $m\n");
$runId = null;
if (!$opt['predict-only']) {
    $r = FighterSync::make($say)->run($opt['budget'], $opt['market'] ? [$opt['market']] : null, $opt['relink'], $opt['deep']);
    $runId = $r['run_id'];
}

EloRatings::reset();
$models = FightModel::all(db());
foreach ($models as $m) {
    $m->predictAll($runId, $say);
}

// Summary: model vs Kalshi for every linked open market (or the one asked for).
$sql = "SELECT m.id, m.yes_subtitle, e.event_title, f.full_name, o.full_name AS opp,
               (SELECT yes_price FROM market_snapshots s WHERE s.market_id = m.id ORDER BY captured_at DESC LIMIT 1) AS kalshi
          FROM market_fighters mf
          JOIN markets m ON m.id = mf.market_id
          JOIN events e ON e.id = m.event_id
          JOIN fighters f ON f.id = mf.fighter_id
     LEFT JOIN fighters o ON o.id = mf.opponent_id
         WHERE " . ($opt['market'] ? 'm.id = ' . (int) $opt['market'] : "m.status = 'open'") . '
      ORDER BY e.event_start_time NULLS LAST, m.id';
$rows = db()->query($sql)->fetchAll();
echo "\n" . str_pad('Market', 34);
foreach ($models as $m) {
    echo str_pad($m->version, 6);
}
echo str_pad('Kalshi', 8) . "Note\n";
foreach ($rows as $r) {
    $ps = [];
    foreach ($models as $m) {
        $x = $m->predictMarket((int) $r['id']);
        $ps[] = $x['ok'] ? round($x['p'] * 100) . '%' : '—';
        $res ??= $x;   // notes / reasons from the first version
    }
    $k   = $r['kalshi'] !== null ? round((float) $r['kalshi'] * 100) . '%' : '—';
    $why = $res['ok'] ? implode(' ', array_slice($res['notes'], 0, 1)) : [
        'not_linked' => 'not matched to ESPN', 'no_opponent' => 'opponent not matched',
        'not_synced' => 'fighter data not synced yet (run again)', 'no_history' => 'no fight history on ESPN',
    ][$res['reason']] ?? $res['reason'];
    printf("%-34s%s%-8s%s\n", substr("#{$r['id']} {$r['full_name']} vs " . ($r['opp'] ?? '?'), 0, 33),
        implode('', array_map(fn($x) => str_pad($x, 6), $ps)), $k, $why);
    unset($res);
}
if (!$rows) {
    echo "No linked markets. Run a scan first so Kalshi markets exist, then run this again.\n";
}
