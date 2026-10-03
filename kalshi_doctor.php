<?php
/**
 * Kalshi connection doctor — checks every setting, the key, the connection and the signature in one run.
 *   php kalshi_doctor.php          (run from the project folder, next to config.php)
 * Prints no secrets. Delete it when you're done.
 */
declare(strict_types=1);

$fails = 0;
function ok(string $m): void { echo "  OK    $m
"; }
function info(string $m): void { echo "        $m
"; }
function warn(string $m): void { echo "  WARN  $m
"; }
function fail(string $m, string $fix): void { global $fails; $fails++; echo "  FAIL  $m
  FIX → $fix
"; }
function mask(?string $v): string { return $v === null ? '(not set)' : (strlen($v) > 12 ? substr($v, 0, 8) . '…' . substr($v, -4) : '(set, ' . strlen($v) . ' chars)'); }

function http_get(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($ca = env('CA_BUNDLE')) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    } elseif (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $raw  = curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $out  = [
        'code'    => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'err'     => curl_error($ch),
        'headers' => $raw === false ? '' : substr($raw, 0, $size),
        'body'    => $raw === false ? '' : substr($raw, $size),
    ];
    curl_close($ch);
    return $out;
}

/* ------------------------------------------------------------------ */
echo "
1. config.php
";
try {
    require __DIR__ . '/config.php';
    ok('config.php loaded (database connected)');
} catch (Throwable $e) {
    fail('config.php failed: ' . $e->getMessage(), 'Fix this first — usually the DB_* values in .env or a missing composer package.');
    exit(1);
}

/* ------------------------------------------------------------------ */
echo "
2. .env values PHP actually sees
";
$idVars  = ['KALSHI_ACCESS_KEY'];
$pathVar = ['KALSHI_PRIVATE_KEY_PATH'];
$pemVars = ['KALSHI_PRIVATE_KEY', 'K_RSA_KEY', 'K_PRIVATE_KEY'];

$ids = [];
foreach ($idVars as $v) {
    info(str_pad($v, 24) . mask(env($v)));
    if (env($v) !== null) { $ids[$v] = trim(env($v), " \t\"'"); }
}
foreach ($pathVar as $v) {
    if (env($v) !== null) { info(str_pad($v, 24) . env($v)); }
}
foreach ($pemVars as $v) {
    if (env($v) !== null) { info(str_pad($v, 24) . '(set, ' . strlen(env($v)) . ' chars) — this OVERRIDES the key file'); }
}
info(str_pad('KALSHI_BASE_URL', 24) . (env('KALSHI_BASE_URL') ?? '(not set → default)') . '  → using ' . KALSHI_BASE_URL);
//info(str_pad('KALSHI_USE_AUTH', 24) . (env('KALSHI_USE_AUTH') ?? '(not set → 1)'));
info(str_pad('CA_BUNDLE', 24) . (env('CA_BUNDLE') ?? '(not set → Windows certificate store)'));

if (count(array_unique($ids)) > 1) {
    fail('Different Key IDs are set in ' . implode(', ', array_keys($ids)) . '. PHP uses ' . (KALSHI_ACCESS_KEY === ($ids['KALSHI_ACCESS_KEY'] ?? null) ? 'KALSHI_ACCESS_KEY' : 'the first one set') . '.',
        'Put the NEW key\'s ID in all of them (or delete the old ones).');
}

$keyId = KALSHI_ACCESS_KEY;
if ($keyId === null) {
    fail('No Key ID found.', 'Add K_KEY=<your new Key ID> to .env (the UUID shown next to the key on kalshi.com).');
} elseif (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $keyId)) {
    fail('Key ID "' . mask($keyId) . '" is not a UUID (' . strlen($keyId) . ' chars).',
        'Use the Key ID (looks like a952bcbe-ec3b-4b5b-b8f9-11dae589608c). Remove quotes/spaces. Not the key file contents or path.');
} else {
    ok('Key ID looks valid: ' . mask($keyId));
}

if (!str_ends_with(KALSHI_BASE_URL, '/trade-api/v2')) {
    fail('KALSHI_BASE_URL does not end in /trade-api/v2.', 'Set KALSHI_BASE_URL=https://api.elections.kalshi.com/trade-api/v2');
}
if (str_contains(KALSHI_BASE_URL, 'demo')) {
    warn('You are pointed at the DEMO API — only demo-site keys work there, and market data is fake.');
}

/* ------------------------------------------------------------------ */
echo "
3. Private key
";
$pem = null;
if (KALSHI_PRIVATE_KEY_PEM !== null) {
    $pem = str_replace('
', "
", KALSHI_PRIVATE_KEY_PEM);
    if (!str_contains($pem, '-----BEGIN')) {
        fail('A key-TEXT variable (' . implode('/', array_filter($pemVars, fn($v) => env($v) !== null)) . ') is set but does not contain a key — it overrides your key file.',
            'Delete that line from .env (or rename it to KALSHI_PRIVATE_KEY_PATH if it holds a path).');
        $pem = null;
    } else {
        warn('Using key TEXT from .env instead of the file ' . KALSHI_PRIVATE_KEY_PATH . '. Make sure it is the NEW key.');
    }
} else {
    $path = KALSHI_PRIVATE_KEY_PATH;
    info('Key file: ' . $path);
    if (!is_file($path)) {
        fail('Key file not found.', 'Fix KALSHI_PRIVATE_KEY_PATH in .env. Use forward slashes, e.g. C:/Users/angel/market_anal/kalshi.key');
    } else {
        $pem = (string) file_get_contents($path);
        info('Modified ' . date('Y-m-d H:i', filemtime($path)) . ', ' . strlen($pem) . ' bytes, ' . count(preg_split('/\R/', trim($pem))) . ' lines');
    }
}

$keyOk = false;
if ($pem !== null) {
    if (str_starts_with($pem, "\xEF\xBB\xBF")) {
        warn('File starts with an invisible BOM (saved by Notepad). Stripping it for this test.');
        $pem = substr($pem, 3);
    }
    $first = trim(strtok(ltrim($pem), "
"));
    info('First line: ' . $first);

    if (str_contains($first, 'PUBLIC KEY')) {
        fail('This is a PUBLIC key.', 'Use the PRIVATE key file Kalshi downloaded when you created the key.');
    } elseif (str_contains($first, 'ENCRYPTED')) {
        fail('The key is password-protected.', 'Use the unencrypted .key file exactly as Kalshi downloaded it.');
    } elseif (str_contains($first, 'OPENSSH')) {
        fail('This is an OpenSSH-format key, not what Kalshi gives you.', 'Use the .key/.pem file downloaded from Kalshi.');
    } else {
        $res = function_exists('openssl_pkey_get_private') ? @openssl_pkey_get_private($pem) : false;
        if ($res === false) {
            fail('OpenSSL cannot read the key: ' . (openssl_error_string() ?: 'unknown format'),
                'Re-download / re-copy the private key. Open it in Notepad: it must start with -----BEGIN and end with -----END …KEY----- with nothing else.');
        } else {
            $d = openssl_pkey_get_details($res);
            if (($d['type'] ?? null) === OPENSSL_KEYTYPE_RSA) {
                ok('RSA private key, ' . $d['bits'] . ' bits');
                $keyOk = true;
            } else {
                fail('Not an RSA key (OpenSSL type ' . ($d['type'] ?? '?') . ', ' . ($d['bits'] ?? '?') . ' bits — probably Ed25519).',
                    'On kalshi.com create a new API key and switch the type to RSA before creating it.');
            }
        }
    }
}

if ($keyOk) {
    if (!class_exists(\phpseclib3\Crypt\PublicKeyLoader::class)) {
        fail('phpseclib is not installed.', 'Run: composer require phpseclib/phpseclib:~3.0');
        $keyOk = false;
    } else {
        try {
            kalshi_private_key();
            ok('phpseclib loaded the key for RSA-PSS signing');
        } catch (Throwable $e) {
            fail('phpseclib could not load it: ' . $e->getMessage(),
                'If the file has a BOM or extra text, re-save it as plain UTF-8 (no BOM) with only the BEGIN…END block.');
            $keyOk = false;
        }
    }
}

/* ------------------------------------------------------------------ */
echo "
4. Connection (no key needed)
";
$r = http_get(KALSHI_BASE_URL . '/exchange/status');
if ($r['code'] === 0) {
    fail('Could not connect: ' . $r['err'],
        str_contains($r['err'], 'certificate')
            ? 'Certificate list problem — set CA_BUNDLE in .env to the certifi path, or use PHP 8.2+ for the Windows store.'
            : 'Check your internet, firewall, VPN or antivirus.');
} else {
    ok("Reached Kalshi (HTTP {$r['code']}) " . substr(trim($r['body']), 0, 80));
    if (preg_match('/^date:\s*(.+)$/mi', $r['headers'], $m) && ($server = strtotime(trim($m[1])))) {
        $skew = time() - $server;
        abs($skew) > 5
            ? fail("Your PC clock is off by {$skew} seconds. Kalshi rejects signatures with a bad timestamp.",
                'Windows Settings → Time & language → Date & time → turn on "Set time automatically" and click "Sync now".')
            : ok("PC clock is in sync ({$skew}s)");
    }
}

$src = (string) @file_get_contents(__DIR__ . '/src/KalshiClient.php');
if (!str_contains($src, 'CURLSSLOPT_NATIVE_CA') && !str_contains($src, 'CURLOPT_CAINFO')) {
    fail('src/KalshiClient.php does not have the certificate fix — the scanner will still fail even if this test passes.',
        'Paste the CA_BUNDLE / CURLSSLOPT_NATIVE_CA block right after curl_setopt_array(...) in get(). Same in src/NewsClient.php.');
} else {
    ok('src/KalshiClient.php has the certificate fix');
}

/* ------------------------------------------------------------------ */
echo "
5. Signed request (your key)
";
if (!$keyOk || $keyId === null || $r['code'] === 0) {
    info('Skipped — fix the FAIL lines above first.');
} else {
    $path = rtrim((string) parse_url(KALSHI_BASE_URL, PHP_URL_PATH), '/') . '/portfolio/balance';
    $s    = http_get(KALSHI_BASE_URL . '/portfolio/balance', kalshi_auth_headers('GET', $path));
    if ($s['code'] === 200) {
        ok('Kalshi accepted your signature — the key works. ' . substr(trim($s['body']), 0, 60));
    } else {
        $detail = json_decode($s['body'], true)['error']['details'] ?? '';
        $fix = match (true) {
            $detail === 'NOT_FOUND' => 'Kalshi does not recognise this Key ID. Copy the ID of the NEW key from kalshi.com → API Keys into .env (all Key ID variables), and make sure it is a kalshi.com key, not demo.',
            str_contains(strtolower($s['body']), 'signature') => 'The key FILE does not belong to this Key ID. Use the .key file downloaded with this exact key.',
            str_contains(strtolower($s['body']), 'timestamp') => 'Sync your Windows clock (see step 4).',
            default => 'Make sure the Key ID and the key file come from the same, newly created RSA key.',
        };
        fail("Kalshi rejected the signature (HTTP {$s['code']}): " . substr(trim($s['body']), 0, 160), $fix);
    }
}

/* ------------------------------------------------------------------ */
echo "
" . ($fails === 0
    ? "ALL GOOD. Set KALSHI_USE_AUTH=1 and run: php cron\\scan.php
"
    : "$fails problem(s) found. Fix the FAIL lines from the top down, then run this again.
");