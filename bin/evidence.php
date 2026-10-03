<?php
/**
 * Re-run the live evidence search for a drop and print what each source found.
 *
 *   php bin/evidence.php 123                 all configured sources for movement #123
 *   php bin/evidence.php 123 espn espn_news  only these sources (espn, espn_plays, espn_news, kalshi_trades)
 *   php bin/evidence.php latest              the most recent drop
 *   php bin/evidence.php fights              refresh ESPN fight info for every open bout and print it
 *   php bin/evidence.php closes [hours]      record missed "market closed" jumps (default: last 72 h) and explain them
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';

$arg = $argv[1] ?? '';
if ($arg === 'closes') {
    // Markets that closed in the last N hours (default 72) without a recorded closing jump → add it + ESPN result.
    $hours = (int) ($argv[2] ?? 72);
    $ids   = Scanner::make(fn($m) => print("$m\n"))->backfillCloses($hours);
    echo $ids ? count($ids) . ' close movement(s) added: #' . implode(', #', $ids) . "\n" : "No missed closes in the last {$hours}h.\n";
    exit(0);
}
if ($arg === 'fights') {
    db()->exec('UPDATE events SET espn_checked_at = NULL');   // force a refresh
    EvidenceCollector::make(db(), new KalshiClient())->refreshFightInfo(fn($m) => print("$m\n"));
    $rows = db()->query("SELECT e.event_title, e.espn_data, (SELECT yes_subtitle FROM markets WHERE event_id = e.id LIMIT 1) AS subj
                           FROM events e WHERE e.status = 'open' AND e.sport = 'UFC' ORDER BY e.event_start_time")->fetchAll();
    foreach ($rows as $r) {
        $f = espn_fight_info($r['espn_data'], $r['subj']);
        echo "\n{$r['event_title']}: " . ($f ? trim(implode(' · ', array_filter([$f['weight_class'], $f['state'], $f['detail'], $f['result']]))) : 'not found on ESPN') . "\n";
        foreach ($f['fighters'] ?? [] as $x) {
            printf("  %-24s %-9s odds %-6s %s\n", $x['name'], $x['record'] ?? '—', $x['odds'] ?? '—',
                $x['stats'] ? json_encode($x['stats']) : '');
        }
    }
    exit(0);
}
if ($arg === 'latest') {
    $arg = (string) db()->query('SELECT id FROM market_movements ORDER BY detected_at DESC LIMIT 1')->fetchColumn();
}
if (!ctype_digit($arg)) {
    fwrite(STDERR, "Usage: php bin/evidence.php <movement_id|latest|fights|closes> [espn|espn_plays|espn_news|kalshi_trades ...]\n");
    exit(1);
}
$only = array_slice($argv, 2) ?: null;
if ($only && ($bad = array_diff($only, EVIDENCE_SOURCES))) {
    fwrite(STDERR, 'Unknown source(s): ' . implode(', ', $bad) . "\n");
    exit(1);
}

$collector = EvidenceCollector::make(db(), new KalshiClient());
echo 'Configured: ' . (implode(', ', $collector->enabledSources()) ?: 'none') . "\n";
$collector->collect((int) $arg, $only);

$exp = movement_explanation((int) $arg);
echo "\nMovement #$arg — {$collector->lastSummary}\n\n";
foreach ($exp['timeline'] as $i) {
    printf("  %s  %-13s %-11s %s\n", $i['at'] ? fmt_time($i['at'], 'M j g:i A') : '—', $i['label'],
        $i['stance'] === 'neutral' ? '' : $i['stance'], $i['headline']);
    if ($i['detail']) {
        printf("  %s  %-13s %-11s %s\n", str_repeat(' ', strlen(fmt_time($i['at'] ?? 'now', 'M j g:i A'))), '', '', $i['detail']);
    }
}
foreach ($exp['checked'] as $c) {
    if ($c['note'] && $c['status'] !== 'found') {
        echo "\n  {$c['label']}: {$c['status']} — {$c['note']}";
    }
}
echo "\n\nHeadline: " . ($exp['headline'] ? "[{$exp['headline']['label']}] {$exp['headline']['headline']}" : 'none') . "\n";
