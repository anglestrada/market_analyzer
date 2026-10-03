<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$admin = require_admin();

// "Run scan now" — handy on localhost before cron is set up.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    csrf_check();
    session_write_close();          // don't lock the session while the scan runs
    set_time_limit(280);
    try {
        $r = Scanner::make()->run();
        session_start();
        flash(sprintf('Scan #%d %s: %d markets, %d movements.', $r['scan_run_id'], $r['status'],
            $r['markets_found'] ?? 0, $r['movements_detected'] ?? 0), $r['status'] === 'completed' ? 'ok' : 'error');
    } catch (Throwable $e) {
        session_start();
        error_log('[admin/scan] ' . $e);
        flash('Scan failed: ' . $e->getMessage(), 'error');
    }
    redirect('/admin/scans.php');
}

$runs = db()->query('SELECT * FROM scan_runs ORDER BY id DESC LIMIT 100')->fetchAll();
$totals = db()->query(
    "SELECT (SELECT COUNT(*) FROM markets) AS markets,
            (SELECT COUNT(*) FROM markets WHERE status = 'open') AS open_markets,
            (SELECT COUNT(*) FROM market_snapshots) AS snapshots,
            (SELECT COUNT(*) FROM market_movements) AS movements,
            (SELECT COUNT(*) FROM articles) AS articles"
)->fetch();

page_header('Scans', $admin);
?>
<div class="page-head">
    <h1>Scan runs</h1>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="run">
        <button>Run scan now</button>
    </form>
</div>

<div class="stats compact">
    <div><span>Markets</span><b><?= fmt_int($totals['markets']) ?></b><small><?= fmt_int($totals['open_markets']) ?> open</small></div>
    <div><span>Snapshots</span><b><?= fmt_int($totals['snapshots']) ?></b><small>kept forever</small></div>
    <div><span>Movements</span><b><?= fmt_int($totals['movements']) ?></b><small>≥ <?= (int) MOVEMENT_THRESHOLD_PP ?> pts</small></div>
    <div><span>Articles</span><b><?= fmt_int($totals['articles']) ?></b><small>relevant only</small></div>
    <div><span>Kalshi auth</span><b><?= kalshi_auth_available() ? 'signed' : 'public' ?></b><small><?= e(KALSHI_UFC_SERIES) ?></small></div>
    <div><span>NewsAPI</span><b><?= NEWSAPI_KEY ? 'configured' : 'missing key' ?></b><small><?= NEWS_LOOKBACK_HOURS ?>h lookback</small></div>
</div>

<section class="card">
    <table class="table">
        <thead><tr><th>#</th><th>Started</th><th>Duration</th><th>Status</th><th>Found</th><th>Updated</th><th>Movements</th><th>Errors</th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r):
            $dur = $r['finished_at'] ? strtotime($r['finished_at']) - strtotime($r['started_at']) : null; ?>
            <tr>
                <td><?= (int) $r['id'] ?></td>
                <td class="nowrap"><?= e(fmt_time($r['started_at'])) ?></td>
                <td><?= $dur === null ? '—' : $dur . 's' ?></td>
                <td><?= badge($r['status']) ?></td>
                <td><?= (int) $r['markets_found'] ?></td>
                <td><?= (int) $r['markets_updated'] ?></td>
                <td><?= (int) $r['movements_detected'] ?></td>
                <td class="small">
                    <?php if ($r['error_message']): ?>
                        <details><summary><?= e(mb_strimwidth(strtok($r['error_message'], "\n"), 0, 80, '…')) ?></summary>
                            <pre><?= e($r['error_message']) ?></pre></details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php page_footer();
