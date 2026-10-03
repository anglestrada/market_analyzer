<?php
/**
 * Kalshi UFC scanner.
 *
 *   php cron/scan.php          run one scan (use this from cron / Render Cron Job)
 *   php cron/scan.php --loop   localhost: run now, then every 5 minutes until Ctrl+C
 *
 * crontab (Linux/macOS):
 *   * /5 * * * * cd /path/to/kalshi-analyzer && php cron/scan.php >> storage/scan.log 2>&1
 *   (remove the space between * and /5)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';

$loop = in_array('--loop', $argv, true);
$log  = fn(string $m) => fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $m . PHP_EOL);

do {
    $started = time();
    try {
        $result = Scanner::make($log)->run();
        $exit   = $result['status'] === 'failed' ? 1 : 0;
    } catch (Throwable $e) {
        $log('FATAL: ' . $e->getMessage());
        error_log('[scan] ' . $e);
        $exit = 1;
    }

    if ($loop) {
        $sleep = max(0, 300 - (time() - $started));
        $log("Next scan in {$sleep}s (Ctrl+C to stop).");
        sleep($sleep);
    }
} while ($loop);

exit($exit);
