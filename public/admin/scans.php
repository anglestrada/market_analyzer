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

$health = chart_scan_health(48);
$okRate = $health ? 100 * count(array_filter($health, fn($r) => $r['status'] === 'completed')) / count($health) : null;

page_header('Scans', $admin);
?>
<div class="page-head">
    <h1>Scan runs</h1>
    <form method="post" data-busy="Scanning… this can take a minute">
        <?= csrf_field() ?><input type="hidden" name="action" value="run">
        <button>Run scan now</button>
    </form>
</div>

<div class="kpis">
    <?= kpi('Markets', count_num((int) $totals['markets']), fmt_int($totals['open_markets']) . ' open', 'accent', 'markets') ?>
    <?= kpi('Snapshots', count_num((int) $totals['snapshots']), 'kept forever', 'sky', 'scans') ?>
    <?= kpi('Movements', count_num((int) $totals['movements']), fmt_int($totals['articles']) . ' relevant articles', 'amber', 'movements') ?>
    <?= kpi('Success · 48h', $health ? count_num($okRate, 0, '%') : '—', count($health) . ' scans', $okRate !== null && $okRate < 90 ? 'red' : 'green', 'scans') ?>
</div>

<div class="stat-chips">
    <div><span>Kalshi requests</span><b><?= kalshi_auth_available() ? 'signed' : 'public' ?></b></div>
    <div><span>Series</span><b><?= e(KALSHI_UFC_SERIES) ?></b></div>
    <div><span>TheNewsAPI</span><b><?= NEWS_API_TOKEN ? 'configured' : 'missing token' ?></b></div>
    <div><span>News search</span><b><?= NEWS_PAGE_SIZE ?> per page · up to <?= NEWS_MAX_PAGES ?> pages</b></div>
    <div><span>"Enough" score</span><b>≥ <?= NEWS_CONFIDENT_SCORE ?></b></div>
    <div><span>News lookback</span><b><?= NEWS_LOOKBACK_HOURS ?>h</b></div>
</div>

<section class="card">
    <div class="card-head"><h2>Scan health · last 48 hours</h2><span class="muted small">dots: <span class="up">completed</span> · <span class="amber-text">partial</span> · <span class="down">failed</span></span></div>
    <div class="chart-box h-260"><canvas data-chart="scanHealth" data-source="d-health" data-empty="No scans in the last 48 hours."></canvas></div>
    <?= json_script('d-health', $health) ?>
</section>

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
