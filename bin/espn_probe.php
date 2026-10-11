<?php
/**
 * ESPN MMA data probe: shows what ESPN actually returns for a fighter, to decide whether
 * ESPN alone is enough for the fight-prediction view. Read-only; no database, no .env.
 *
 *   php bin/espn_probe.php "Payton Talbott"              name search
 *   php bin/espn_probe.php 4423876                       ESPN athlete id
 *   php bin/espn_probe.php "Payton Talbott" --fights=5 --opp=2 --raw
 *
 *   --fights=N   how many recent fights to inspect (default 5)
 *   --opp=N      for how many of those fights to rebuild the opponent's record at the time
 *                from the opponent's own history (expensive: ~1 request per opponent fight; default 1)
 *   --raw        also print the first raw JSON response of each kind (trimmed)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit; }

/* ------------------------------------------------------------------ args */
$args = array_slice($argv, 1);
$opt  = ['fights' => 5, 'opp' => 1, 'raw' => false];
$who  = null;
foreach ($args as $a) {
    if (preg_match('/^--(fights|opp)=(\d+)$/', $a, $m)) { $opt[$m[1]] = (int) $m[2]; }
    elseif ($a === '--raw') { $opt['raw'] = true; }
    else { $who = $a; }
}
if ($who === null) {
    fwrite(STDERR, "Usage: php bin/espn_probe.php \"Fighter Name\"|athleteId [--fights=5] [--opp=1] [--raw]
");
    exit(1);
}

const CORE = 'https://sports.core.api.espn.com/v2/sports/mma';
const SITE = 'https://site.api.espn.com/apis/site/v2/sports/mma/ufc';

$requests = 0;
$cache    = [];
$rawShown = [];

/* ------------------------------------------------------------------ http */
function get(string $url, bool $quiet = false): ?array
{
    global $requests, $cache;
    $url = preg_replace('#^http://#', 'https://', $url);
    if (array_key_exists($url, $cache)) { return $cache[$url]; }

    usleep(250000);   // be polite: ~4 requests/second at most
    $requests++;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'KalshiMarketAnalyzer/1.0 (espn probe)',
    ]);
    if (defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status >= 400) {
        if (!$quiet) { echo "    ! $status " . ($err ?: '') . " $url
"; }
        if ($status === 429 || $status === 403) {
            echo "
ESPN is refusing requests (HTTP $status). Stopping; try again later.
";
            exit(2);
        }
        return $cache[$url] = null;
    }
    $data = json_decode((string) $body, true);
    return $cache[$url] = is_array($data) ? $data : null;
}

/** Follows {"$ref": "..."} links; returns the node itself if it's already expanded. */
function ref(mixed $node): ?array
{
    if (!is_array($node)) { return null; }
    if (isset($node['$ref']) && count($node) <= 2) { return get((string) $node['$ref'], true); }
    return $node;
}

function raw(string $kind, ?array $data): void
{
    global $opt, $rawShown;
    if (!$opt['raw'] || $data === null || isset($rawShown[$kind])) { return; }
    $rawShown[$kind] = true;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo "
  --- raw $kind (first 2500 chars) ---
" . substr((string) $json, 0, 2500) . "
  ---
";
}

function idFromRef(?string $ref): ?string
{
    return ($ref && preg_match('#/athletes/(\d+)#', $ref, $m)) ? $m[1] : null;
}

/** Every string anywhere in $data that mentions a title / championship. */
function titleHints(array $data): array
{
    $hits = [];
    array_walk_recursive($data, function ($v, $k) use (&$hits) {
        if (is_string($v) && preg_match('/title|championship|belt|interim/i', $v) && !str_contains($v, 'http')) {
            $hits[] = "$k=\"$v\"";
        }
    });
    return array_values(array_unique($hits));
}

function line(string $label, mixed $value): void
{
    printf("  %-26s %s
", $label, is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value));
}

/* ------------------------------------------------------------------ 1. find the athlete */
echo "
1. Find athlete
";
$athleteId = ctype_digit($who) ? $who : null;

if ($athleteId === null) {
    $q = rawurlencode($who);
    foreach ([
        "https://site.web.api.espn.com/apis/search/v2?query=$q&limit=10",
        "https://site.web.api.espn.com/apis/common/v3/search?query=$q&limit=10&type=player",
    ] as $url) {
        $data = get($url);
        raw('search', $data);
        if ($data === null) { continue; }
        array_walk_recursive($data, function ($v, $k) use (&$athleteId) {
            if ($athleteId === null && is_string($v) && str_contains($v, '/mma/') && preg_match('#/(?:id/|athletes/)(\d+)#', $v, $m)) {
                $athleteId = $m[1];
            }
        });
        if ($athleteId !== null) { echo "  found via " . parse_url($url, PHP_URL_PATH) . "
"; break; }
    }
}
if ($athleteId === null) {
    // Fallback: look for the name on recent and upcoming UFC scoreboards (±8 weeks).
    $needle = strtolower($who);
    for ($w = -8; $w <= 8 && $athleteId === null; $w++) {
        $day  = date('Ymd', strtotime("$w weeks"));
        $data = get(SITE . "/scoreboard?dates=$day", true) ?? [];
        foreach ($data['events'] ?? [] as $e) {
            foreach ($e['competitions'] ?? [] as $c) {
                foreach ($c['competitors'] ?? [] as $cp) {
                    if (str_contains(strtolower((string) ($cp['athlete']['displayName'] ?? '')), $needle)) {
                        $athleteId = (string) ($cp['athlete']['id'] ?? $cp['id']);
                    }
                }
            }
        }
    }
    if ($athleteId !== null) { echo "  found on a scoreboard
"; }
}
if ($athleteId === null) {
    echo "  Could not find \"$who\". Get the id from the fighter's espn.com URL (…/mma/fighter/_/id/<ID>/…) and pass it.
";
    exit(1);
}
line('athlete id', $athleteId);

/* ------------------------------------------------------------------ 2. profile */
echo "
2. Profile
";
$profile = get(CORE . "/athletes/$athleteId") ?? get(CORE . "/leagues/ufc/athletes/$athleteId");
raw('profile', $profile);
if ($profile) {
    foreach (['displayName', 'age', 'dateOfBirth', 'displayHeight', 'displayWeight', 'displayReach', 'reach',
              'stance', 'association', 'citizenship', 'weightClass'] as $k) {
        if (isset($profile[$k])) { line($k, is_array($profile[$k]) ? ($profile[$k]['text'] ?? $profile[$k]['name'] ?? $profile[$k]) : $profile[$k]); }
    }
    $rec = ref($profile['records'] ?? $profile['record'] ?? null);
    if ($rec) { line('record node keys', array_keys($rec)); }
    line('all profile keys', array_keys($profile));
} else {
    echo "  profile not available
";
}

/* ------------------------------------------------------------------ 3. fight history */
echo "
3. Fight history (eventlog)
";
$log = get(CORE . "/athletes/$athleteId/eventlog?limit=100") ?? get(CORE . "/leagues/ufc/athletes/$athleteId/eventlog?limit=100");
raw('eventlog', $log);
$items = $log['events']['items'] ?? $log['items'] ?? [];
line('fights listed', count($items));
if (!$items) { echo "  No event log — ESPN can't supply fight history for this fighter.
"; }
if ($items) { line('item keys', array_keys($items[0])); }

/**
 * Loads one fight from an eventlog item: date, both fighters, winner, method, title hints, record refs.
 * @return array|null
 */
function loadFight(array $item, string $selfId): ?array
{
    $comp = ref($item['competition'] ?? null);
    if (!$comp) {
        $event = ref($item['event'] ?? null);
        $comp  = ref($event['competitions'][0] ?? null);
    }
    if (!$comp) { return null; }
    raw('competition', $comp);

    $me = $opp = null;
    foreach ($comp['competitors'] ?? [] as $c) {
        $c   = ref($c) ?? $c;
        $aid = (string) ($c['id'] ?? '') ?: idFromRef($c['athlete']['$ref'] ?? null);
        $row = ['id' => $aid, 'node' => $c, 'winner' => $c['winner'] ?? null];
        if ($aid === $selfId || idFromRef($c['athlete']['$ref'] ?? null) === $selfId) { $me = $row; } else { $opp = $row; }
    }
    $status = ref($comp['status'] ?? null) ?? [];
    raw('status', $status);
    return [
        'comp'   => $comp,
        'date'   => $comp['date'] ?? $comp['startDate'] ?? null,
        'me'     => $me,
        'opp'    => $opp,
        'result' => $me['winner'] === true ? 'W' : ($opp && $opp['winner'] === true ? 'L' : '?'),
        'method' => $status['result']['displayName'] ?? $status['result']['name'] ?? ($status['type']['detail'] ?? null),
        'round'  => $status['period'] ?? null,
        'titles' => titleHints(array_diff_key($comp, ['competitors' => 1])) ?: titleHints($status),
    ];
}

/* ------------------------------------------------------------------ 4. recent fights in detail */
echo "
4. Last {$opt['fights']} fights in detail
";
$checkedOpp = 0;
$statNames  = [];
foreach (array_slice($items, 0, $opt['fights']) as $n => $item) {
    $f = loadFight($item, $athleteId);
    if (!$f) { echo "  #" . ($n + 1) . " could not load competition
"; continue; }

    $oppProfile = $f['opp'] ? ref($f['opp']['node']['athlete'] ?? null) : null;
    $oppName    = $oppProfile['displayName'] ?? ('athlete ' . ($f['opp']['id'] ?? '?'));
    printf("
  #%d  %s  %s vs %s  %s  %s
", $n + 1, substr((string) $f['date'], 0, 10), $f['result'], $oppName,
        $f['method'] ?? '', $f['round'] ? 'R' . $f['round'] : '');
    line('  title hints', $f['titles'] ?: 'none found');

    // Record at the time, as ESPN reports it on the competitor (cheap, if ESPN has it).
    foreach (['me' => 'own record then', 'opp' => 'opp record then'] as $side => $label) {
        $node = $f[$side]['node'] ?? [];
        $rec  = ref($node['records'] ?? $node['record'] ?? null);
        $sum  = $rec['summary'] ?? $rec['items'][0]['summary'] ?? $rec[0]['summary'] ?? null;
        line("  $label (ESPN)", $sum ?? 'not provided');
    }

    // Per-fight stats
    $stats = ref($f['me']['node']['statistics'] ?? null);
    raw('statistics', $stats);
    $found = [];
    if ($stats) {
        array_walk_recursive($stats, function ($v, $k) {});   // no-op; keeps structure intact
        $walk = function (array $node) use (&$walk, &$found) {
            if (isset($node['name']) && array_key_exists('value', $node)) { $found[$node['name']] = $node['displayValue'] ?? $node['value']; }
            foreach ($node as $v) { if (is_array($v)) { $walk($v); } }
        };
        $walk($stats);
    }
    $statNames += $found;
    line('  stats', $found ? implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($found), $found)) : 'none');

    // Opponent's record at the time, rebuilt from the opponent's own history (expensive).
    if ($checkedOpp < $opt['opp'] && $f['opp'] && $f['opp']['id'] && $f['date']) {
        $checkedOpp++;
        $before = strtotime((string) $f['date']);
        $oLog   = get(CORE . "/athletes/{$f['opp']['id']}/eventlog?limit=100");
        $w = $l = $d = $skipped = 0;
        foreach ($oLog['events']['items'] ?? $oLog['items'] ?? [] as $oi) {
            $of = loadFight($oi, $f['opp']['id']);
            if (!$of || !$of['date']) { $skipped++; continue; }
            if (strtotime((string) $of['date']) >= $before) { continue; }
            $of['result'] === 'W' ? $w++ : ($of['result'] === 'L' ? $l++ : $d++);
        }
        line('  opp record then (rebuilt)', "$w-$l" . ($d ? "-$d" : '') . ($skipped ? " ($skipped fights unreadable)" : ''));
    }
}

/* ------------------------------------------------------------------ 5. coverage summary */
echo "
5. Coverage over the full history
";
$withStats = $years = 0;
$oldest = null;
foreach ($items as $item) {
    $f = loadFight($item, $athleteId);   // cached after the first pass for the recent ones
    if (!$f) { continue; }
    $oldest = $f['date'] ?? $oldest;
    $s = ref($f['me']['node']['statistics'] ?? null);
    if ($s) { $withStats++; }
}
line('fights in history', count($items));
line('fights with stats', $withStats);
line('oldest fight', $oldest ? substr((string) $oldest, 0, 10) : null);
line('stat names seen', array_keys($statNames));
line('ESPN requests made', $requests);
echo "
Done. Paste this output (not your .env) back into the chat.
";