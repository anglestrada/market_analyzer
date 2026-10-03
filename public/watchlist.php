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

$movements = $items ? fetch_movements(['watch_user_id' => $uid, 'from' => gmdate('c', time() - 7 * 86400)], 100) : [];
$self = url('/watchlist.php');

page_header('Watchlist', $user);
?>
<h1>My watchlist</h1>

<?php if (!$items): ?>
    <p class="muted">You aren't watching any markets yet. Use “☆ Watch” on the <a href="<?= e(url('/index.php')) ?>">Markets</a> page.</p>
<?php else: ?>
<section class="card">
    <table class="table">
        <thead><tr><th>Market</th><th>Status</th><th>YES</th><th>NO</th><th>24h</th><th>Movements (7d)</th><th>Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $m):
            $chg = ($m['yes_price'] !== null && $m['yes_24h'] !== null) ? ((float) $m['yes_price'] - (float) $m['yes_24h']) * 100 : null; ?>
            <tr>
                <td><a href="<?= e(url('/market.php', ['id' => $m['id']])) ?>"><?= e($m['yes_subtitle'] ?: $m['market_title']) ?></a>
                    <div class="muted small"><?= e($m['event_title']) ?></div></td>
                <td><?= badge($m['status']) ?></td>
                <td><?= fmt_price($m['yes_price']) ?></td>
                <td><?= fmt_price($m['no_price']) ?></td>
                <td><?= fmt_pp($chg) ?></td>
                <td><?= (int) $m['moves_7d'] ?: '—' ?></td>
                <td class="small"><?= e(fmt_time($m['added_at'], 'M j, Y')) ?></td>
                <td><?= watch_button((int) $m['id'], true, $self) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2>Significant movements on your watchlist (last 7 days)</h2>
    <?php render_movements_table($movements); ?>
</section>
<?php endif; ?>

<?php page_footer();
