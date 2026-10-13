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
 *   php bin/fighters_sync.php --market=123 --espn=3949584
 *                                               link market 123 to that ESPN athlete by hand (the number in the fighter's
 *                                               espn.com/mma/fighter/_/id/<number>/… URL), then sync it
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';

$opt = ['market' => null, 'espn' => null, 'budget' => 3000, 'relink' => false, 'predict-only' => false, 'deep' => false];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(market|budget|espn)=(\d+)$/', $a, $m)) {
        $opt[$m[1]] = (int) $m[2];
    } elseif (in_array($a, ['--relink', '--predict-only', '--deep'], true)) {
        $opt[substr($a, 2)] = true;
    } else {
        fwrite(STDERR, "Unknown option: $a\nUsage: php bin/fighters_sync.php [--market=ID [--espn=ATHLETE_ID]] [--budget=N] [--relink] [--predict-only] [--deep]\n");
        exit(1);
    }
}

// Output: normal lines get a timestamp; the "fight 12/27 | 140/3000 requests | 2:10" line is redrawn in place
// on a terminal (\r), or printed every 10 seconds when the output goes to a file.
$tty  = function_exists('stream_isatty') && @stream_isatty(STDOUT);
$live = '';          // the line currently drawn in place
$lastPrint = 0.0;
$clear = function () use (&$live): void {
    if ($live !== '') {
        echo "\r" . str_repeat(' ', mb_strlen($live)) . "\r";
        $live = '';
    }
};
$say = function (string $m) use ($clear): void {
    $clear();
    echo '[' . gmdate('H:i:s') . "] $m\n";
};
$progress = function (string $line, bool $transient) use ($say, $tty, &$live, &$lastPrint): void {
    if (!$transient) {
        $say($line);
        return;
    }
    if ($tty) {
        $line = mb_strimwidth($line, 0, 118, '...');
        $pad  = max(0, mb_strlen($live) - mb_strlen($line));
        echo "\r$line" . str_repeat(' ', $pad);
        $live = $line;
    } elseif (microtime(true) - $lastPrint >= 10) {
        $say($line);
        $lastPrint = microtime(true);
    }
};

// Manual link: --market=ID --espn=ATHLETE_ID (kept by every later sync; --relink doesn't touch it either).
if ($opt['espn'] !== null) {
    if (!$opt['market']) {
        fwrite(STDERR, "--espn needs --market=ID (the Kalshi market of that fighter).\n");
        exit(1);
    }
    $db = db();
    $q = $db->prepare('SELECT id, event_id, yes_subtitle FROM markets WHERE id = :id');
    $q->execute([':id' => $opt['market']]);
    $mk = $q->fetch();
    if (!$mk) {
        fwrite(STDERR, "Market #{$opt['market']} not found.\n");
        exit(1);
    }
    $db->prepare('INSERT INTO fighters (espn_athlete_id, full_name) VALUES (:a, :n) ON CONFLICT (espn_athlete_id) DO NOTHING')
       ->execute([':a' => (string) $opt['espn'], ':n' => $mk['yes_subtitle'] ?: "ESPN athlete {$opt['espn']}"]);
    $q = $db->prepare('SELECT id FROM fighters WHERE espn_athlete_id = :a');
    $q->execute([':a' => (string) $opt['espn']]);
    $fid = (int) $q->fetchColumn();
    // The other fighter of the same fight, if that market is already linked.
    $q = $db->prepare('SELECT mf.market_id, mf.fighter_id, mf.upcoming_fight_id FROM market_fighters mf JOIN markets m ON m.id = mf.market_id
                        WHERE m.event_id = :e AND m.id <> :id LIMIT 1');
    $q->execute([':e' => $mk['event_id'], ':id' => $mk['id']]);
    $other = $q->fetch() ?: null;
    $db->prepare("INSERT INTO market_fighters (market_id, fighter_id, opponent_id, upcoming_fight_id, match_method)
                  VALUES (:m, :f, :o, :u, 'manual')
                  ON CONFLICT (market_id) DO UPDATE SET fighter_id = EXCLUDED.fighter_id,
                         opponent_id = COALESCE(EXCLUDED.opponent_id, market_fighters.opponent_id),
                         upcoming_fight_id = COALESCE(EXCLUDED.upcoming_fight_id, market_fighters.upcoming_fight_id),
                         match_method = 'manual', matched_at = NOW()")
       ->execute([':m' => $mk['id'], ':f' => $fid, ':o' => $other['fighter_id'] ?? null, ':u' => $other['upcoming_fight_id'] ?? null]);
    if ($other) {   // and tell the other market who its opponent is
        $db->prepare('UPDATE market_fighters SET opponent_id = :f WHERE market_id = :m AND (opponent_id IS NULL OR opponent_id <> :f2)')
           ->execute([':f' => $fid, ':f2' => $fid, ':m' => $other['market_id']]);
    }
    $say("Linked market #{$mk['id']} (\"{$mk['yes_subtitle']}\") to ESPN athlete {$opt['espn']} by hand" . ($other ? ", opponent from market #{$other['market_id']}" : '') . '.');
    // Sync both markets of the fight.
    $opt['markets'] = array_values(array_filter([(int) $mk['id'], $other ? (int) $other['market_id'] : null]));
}
$runId = null;
if (!$opt['predict-only']) {
    $r = FighterSync::make($say)->onProgress($progress)->run($opt['budget'], $opt['markets'] ?? ($opt['market'] ? [$opt['market']] : null), $opt['relink'], $opt['deep']);
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
if (!$rows && $opt['market']) {
    echo "Market #{$opt['market']} isn't matched to an ESPN fighter. Find the fighter on espn.com (the number in\n"
       . "espn.com/mma/fighter/_/id/<number>/...) and run: php bin/fighters_sync.php --market={$opt['market']} --espn=<number>\n";
} elseif (!$rows) {
    echo "No linked markets. Run a scan first so Kalshi markets exist, then run this again.\n";
}
