<?php
/**
 * Re-run the live evidence search for a drop and print what each source found.
 *
 *   php bin/evidence.php 123                 all configured sources for movement #123
 *   php bin/evidence.php 123 espn reddit     only these sources (espn, x, reddit, kalshi_trades)
 *   php bin/evidence.php latest              the most recent drop
 *
 * Note: an X search costs money (it counts toward X_MONTHLY_BUDGET).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';

$arg = $argv[1] ?? '';
if ($arg === 'latest') {
    $arg = (string) db()->query('SELECT id FROM market_movements ORDER BY detected_at DESC LIMIT 1')->fetchColumn();
}
if (!ctype_digit($arg)) {
    fwrite(STDERR, "Usage: php bin/evidence.php <movement_id|latest> [espn|x|reddit|kalshi_trades ...]\n");
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
