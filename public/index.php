<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user = require_login();
$uid  = (int) $user['id'];

$markets = overview_markets($uid);
$summary = overview_summary($uid);
$drops   = overview_drops(7, 25);

// Selected market: ?market=ID, else the most recent drop, else the first open market.
$ids        = array_column($markets, 'id');
$requested  = (int) ($_GET['market'] ?? 0);
$selectedId = in_array($requested, $ids, true) ? $requested : null;
if ($selectedId === null && $requested > 0) {
    $selectedId = $requested;   // closed markets can still be viewed via a link
}
if ($selectedId === null) {
    foreach ($drops as $d) {
        if (in_array((int) $d['market_id'], $ids, true)) {
            $selectedId = (int) $d['market_id'];
            break;
        }
    }
}
$selectedId ??= $ids[0] ?? null;

$range   = array_key_exists($_GET['range'] ?? '', OVERVIEW_RANGES) ? $_GET['range'] : '24h';
$initial = $selectedId ? market_payload($selectedId, $range, $uid) : null;
if ($initial) {
    log_activity('market_view', $uid, $selectedId, ['source' => 'overview', 'range' => $range]);
}

$vm = [
    'markets'  => $markets,
    'summary'  => $summary,
    'drops'    => $drops,
    'selected' => $initial ? $selectedId : null,
    'initial'  => $initial,
    'range'    => $range,
    'scan'     => last_successful_scan(),
    'latest'   => latest_scan(),
    'is_admin' => $user['role'] === 'admin',
    'preview'  => false,
];

page_header('Overview', $user, ['autorefresh' => 0, 'wide' => true]);
require __DIR__ . '/../templates/overview.php';
page_footer($user);
