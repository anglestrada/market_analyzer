<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user = require_login();
$view = ($_GET['view'] ?? 'active') === 'closed' ? 'closed' : 'active';
$q    = trim((string) ($_GET['q'] ?? ''));

$where  = ['e.sport = :sport', $view === 'active' ? "m.status = 'open'" : "m.status <> 'open'"];
$params = [':sport' => DEFAULT_SPORT, ':uid' => (int) $user['id']];
if ($q !== '') {
    $where[] = "(m.market_title ILIKE :q OR e.event_title ILIKE :q2 OR m.yes_subtitle ILIKE :q3)";
    $params[':q'] = $params[':q2'] = $params[':q3'] = '%' . $q . '%';
}
$order = $view === 'active'
    ? 'e.event_start_time ASC NULLS LAST, e.id, m.yes_subtitle'
    : 'COALESCE(m.close_time, m.updated_at) DESC, e.id, m.yes_subtitle';

$stmt = db()->prepare(
    "SELECT m.id, m.market_title, m.yes_subtitle, m.status, m.close_time,
            e.id AS event_id, e.event_title, e.event_start_time,
            s.yes_price, s.no_price, s.volume, s.captured_at,
            d.yes_price AS yes_24h,
            (SELECT MAX(detected_at) FROM market_movements mm WHERE mm.market_id = m.id) AS last_movement_at,
            (w.id IS NOT NULL) AS watched
       FROM markets m
       JOIN events e ON e.id = m.event_id
  LEFT JOIN LATERAL (SELECT yes_price, no_price, volume, captured_at FROM market_snapshots
                      WHERE market_id = m.id ORDER BY captured_at DESC LIMIT 1) s ON TRUE
  LEFT JOIN LATERAL (SELECT yes_price FROM market_snapshots
                      WHERE market_id = m.id AND captured_at <= NOW() - INTERVAL '24 hours'
                      ORDER BY captured_at DESC LIMIT 1) d ON TRUE
  LEFT JOIN watchlist_items w ON w.market_id = m.id AND w.user_id = :uid
      WHERE " . implode(' AND ', $where) . "
   ORDER BY $order
      LIMIT 400"
);
$stmt->execute($params);

$events = [];
foreach ($stmt->fetchAll() as $row) {
    $events[$row['event_id']]['title'] ??= $row['event_title'];
    $events[$row['event_id']]['start'] ??= $row['event_start_time'];
    $events[$row['event_id']]['markets'][] = $row;
}

$scan   = latest_scan();
$recent = $view === 'active' ? fetch_movements(['from' => gmdate('c', time() - 86400)], 10) : [];
$self   = $_SERVER['REQUEST_URI'] ?? url('/index.php');

page_header('Markets', $user);
?>
<div class="page-head">
    <h1>UFC markets</h1>
    <?php if ($scan): ?>
        <div class="muted">Last scan <?= e(fmt_time($scan['started_at'])) ?> <?= badge($scan['status']) ?>
            · <?= (int) $scan['markets_found'] ?> markets</div>
    <?php else: ?>
        <div class="muted">No scans yet — run <code>php cron/scan.php</code> or use Admin → Scans.</div>
    <?php endif; ?>
</div>

<div class="toolbar">
    <div class="tabs">
        <a href="<?= e(url('/index.php', ['view' => 'active'])) ?>" class="<?= $view === 'active' ? 'active' : '' ?>">Active</a>
        <a href="<?= e(url('/index.php', ['view' => 'closed'])) ?>" class="<?= $view === 'closed' ? 'active' : '' ?>">Closed &amp; settled</a>
    </div>
    <form method="get" class="search">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search fighter or event">
        <button>Search</button>
    </form>
</div>

<?php if ($recent): ?>
<section class="card">
    <h2>Movements in the last 24 hours</h2>
    <?php render_movements_table($recent); ?>
</section>
<?php endif; ?>

<?php if (!$events): ?>
    <p class="muted">No <?= $view === 'active' ? 'open' : 'closed' ?> markets<?= $q !== '' ? ' match “' . e($q) . '”' : '' ?>.</p>
<?php endif; ?>

<?php foreach ($events as $ev): ?>
<section class="card">
    <h2><?= e($ev['title']) ?></h2>
    <?php if ($ev['start']): ?><div class="muted small"><?= e(fmt_time($ev['start'])) ?></div><?php endif; ?>
    <table class="table">
        <thead><tr>
            <th>Market</th><th>YES</th><th>NO</th><th>24h</th><th>Volume</th><th>Last movement</th>
            <th><?= $view === 'active' ? 'Updated' : 'Status' ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($ev['markets'] as $m):
            $chg = ($m['yes_price'] !== null && $m['yes_24h'] !== null)
                ? ((float) $m['yes_price'] - (float) $m['yes_24h']) * 100 : null; ?>
            <tr>
                <td><a href="<?= e(url('/market.php', ['id' => $m['id']])) ?>"><?= e($m['yes_subtitle'] ?: $m['market_title']) ?></a>
                    <?php if ($m['yes_subtitle']): ?><div class="muted small"><?= e($m['market_title']) ?></div><?php endif; ?></td>
                <td><?= fmt_price($m['yes_price']) ?></td>
                <td><?= fmt_price($m['no_price']) ?></td>
                <td><?= fmt_pp($chg) ?></td>
                <td><?= fmt_int($m['volume']) ?></td>
                <td class="small"><?= e(fmt_time($m['last_movement_at'])) ?></td>
                <td class="small"><?= $view === 'active' ? e(fmt_time($m['captured_at'], 'g:i A')) : badge($m['status']) ?></td>
                <td><?= watch_button((int) $m['id'], (bool) $m['watched'], $self) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endforeach; ?>

<?php page_footer();
