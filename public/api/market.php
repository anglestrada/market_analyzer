<?php
/**
 * GET /api/market.php?id=123&range=24h[&view=1]
 * Returns one market's chart series, drop markers and best possible explanation as JSON.
 * Used by the Overview page when you click a market or change the range.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json_out(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if ($user === null) {
    json_out(401, ['error' => 'Your session has expired. Please log in again.', 'login' => url('/login.php')]);
}

$id    = (int) ($_GET['id'] ?? 0);
$range = (string) ($_GET['range'] ?? '24h');

try {
    $payload = $id > 0 ? market_payload($id, $range, (int) $user['id']) : null;
} catch (Throwable $e) {
    error_log('[api/market] ' . $e->getMessage());
    json_out(500, ['error' => 'Could not load this market. Check the database connection and try again.']);
}

if ($payload === null) {
    json_out(404, ['error' => 'Market not found.']);
}

// Count a market view when the user picks a market (not on every range change).
if (!empty($_GET['view'])) {
    log_activity('market_view', null, $id, ['source' => 'overview', 'range' => $payload['range']['key']]);
}

json_out(200, $payload);
