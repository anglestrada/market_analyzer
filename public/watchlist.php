<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user = require_login();
$uid  = (int) $user['id'];

/* ---------- add / remove ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $marketId = (int) ($_POST['market_id'] ?? 0);
    $action   = $_POST['action'] ?? '';

    // Only allow redirects back to a local path.
    $return = (string) ($_POST['return'] ?? '');
    if (!preg_match('#^/(?!/)#', $return)) {
        $return = url('/watchlist.php');
    }

    try {
        if ($uid <= 0) {
            throw new RuntimeException('Your .env USER_ID does not match a row in the users table. Create one with bin/create_user.php.');
        }
        if ($action === 'add') {
            $stmt = db()->prepare(
                'INSERT INTO watchlist_items (user_id, market_id)
                 SELECT :u, id FROM markets WHERE id = :m
                 ON CONFLICT (user_id, market_id) DO NOTHING RETURNING id'
            );
            $stmt->execute([':u' => $uid, ':m' => $marketId]);
            if ($stmt->fetchColumn()) {
                log_activity('watchlist_add', $uid, $marketId);
                flash('Added to your watchlist.');
            }
        } elseif ($action === 'remove') {
            $stmt = db()->prepare('DELETE FROM watchlist_items WHERE user_id = :u AND market_id = :m');
            $stmt->execute([':u' => $uid, ':m' => $marketId]);
            if ($stmt->rowCount()) {
                log_activity('watchlist_remove', $uid, $marketId);
                flash('Removed from your watchlist.');
            }
        }
    } catch (Throwable $e) {
        error_log('[watchlist] ' . $e->getMessage());
        flash($e instanceof PDOException ? 'Could not update the watchlist.' : $e->getMessage(), 'error');
    }

    header('Location: ' . $return);
    exit;
}

/* ---------- list ---------- */
$stmt = db()->prepare(
    "SELECT m.id, m.market_title, m.yes_subtitle, m.status, e.event_title, e.event_start_time, w.created_at AS added_at,
            s.yes_price, s.no_price, s.captured_at,
            d.yes_price AS yes_24h,
            (SELECT COUNT(*) FROM market_movements mm WHERE mm.market_id = m.id
                AND mm.detected_at >= NOW() - INTERVAL '7 days') AS moves_7d
       FROM watchlist_items w
       JOIN markets m ON m.id = w.market_id
       JOIN events  e ON e.id = m.event_id
  LEFT JOIN LATERAL (SELECT yes_price, no_price, captured_at FROM market_snapshots
                      WHERE market_id = m.id ORDER BY captured_at DESC LIMIT 1) s ON TRUE
  LEFT JOIN LATERAL (SELECT yes_price FROM market_snapshots
                      WHERE market_id = m.id AND captured_at <= NOW() - INTERVAL '24 hours'
                      ORDER BY captured_at DESC LIMIT 1) d ON TRUE
      WHERE w.user_id = :u
   ORDER BY (m.status = 'open') DESC, e.event_start_time NULLS LAST, m.yes_subtitle"
);
$stmt->execute([':u' => $uid]);
$items = $stmt->fetchAll();

$ids    = array_column($items, 'id');
$spark  = chart_series($ids, 24, 40);
$openIds = array_slice(array_column(array_filter($items, fn($m) => $m['status'] === 'open'), 'id'), 0, 8);
$weekly = chart_series($openIds, 168, 168);

$names   = array_column($items, null, 'id');
$compare = ['series' => []];
foreach ($openIds as $mid) {
    if (!empty($weekly[$mid])) {
        $compare['series'][] = [
            'label'  => $names[$mid]['yes_subtitle'] ?: $names[$mid]['market_title'],
            'points' => array_map(fn($p) => ['x' => $p['t'], 'y' => round($p['y'] * 100, 2)], $weekly[$mid]),
        ];
    }
}

$movements = $items ? fetch_movements(['watch_user_id' => $uid, 'from' => gmdate('c', time() - 7 * 86400)], 100) : [];
$self      = url('/watchlist.php');

page_header('Watchlist', $user);
?>
<div class="page-head">
    <div>
        <h1>My watchlist</h1>
        <p class="muted">Private to you. Significant movements on these markets are listed below (no notifications are sent).</p>
    </div>
</div>

<?php if (!$items): ?>
    <div class="empty card"><?= icon('star') ?>
        <p>You aren't watching any markets yet. Open a market on the <a href="<?= e(url('/public/index.php')) ?>">Overview</a> and click <b>Watch</b>.</p>
    </div>
<?php else: ?>

<section class="card">
    <div class="card-head"><h2>YES price · last 7 days</h2><span class="muted small">up to 8 open markets · scroll to zoom</span></div>
    <div class="chart-box h-320"><canvas data-chart="compare" data-source="d-compare" data-empty="Not enough price history yet."></canvas></div>
    <?= json_script('d-compare', $compare['series'] ? $compare : null) ?>
</section>

<section class="card">
    <div class="table-wrap">
    <table class="table sortable">
        <thead><tr><th>Market</th><th>Status</th><th data-nosort>24h trend</th><th>YES</th><th>NO</th><th>24h</th><th>Moves · 7d</th><th>Added</th><th data-nosort></th></tr></thead>
        <tbody>
        <?php foreach ($items as $m):
            $chg = ($m['yes_price'] !== null && $m['yes_24h'] !== null) ? ((float) $m['yes_price'] - (float) $m['yes_24h']) * 100 : null; ?>
            <tr>
                <td><a href="<?= e(url('/market.php', ['id' => $m['id']])) ?>"><?= e($m['yes_subtitle'] ?: $m['market_title']) ?></a>
                    <div class="muted small"><?= e($m['event_title']) ?></div></td>
                <td><?= badge($m['status']) ?></td>
                <td><?= sparkline_svg(array_column($spark[$m['id']] ?? [], 'y')) ?></td>
                <td data-value="<?= (float) $m['yes_price'] ?>"><b><?= fmt_price($m['yes_price']) ?></b><?= prob_bar($m['yes_price']) ?></td>
                <td data-value="<?= (float) $m['no_price'] ?>"><?= fmt_price($m['no_price']) ?></td>
                <td data-value="<?= $chg ?? 0 ?>"><?= fmt_pp($chg) ?></td>
                <td data-value="<?= (int) $m['moves_7d'] ?>"><?= (int) $m['moves_7d'] ?: '<span class="muted">—</span>' ?></td>
                <td class="small" data-value="<?= strtotime($m['added_at']) ?>"><?= e(fmt_time($m['added_at'], 'M j, Y')) ?></td>
                <td><?= watch_button((int) $m['id'], true, $self, true) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <div class="card-head"><h2>Significant movements · last 7 days</h2></div>
    <?php render_movements_table($movements); ?>
</section>
<?php endif; ?>

<?php page_footer();
