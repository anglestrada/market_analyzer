<?php
/**
 * Kalshi Market Analyzer — central configuration.
 *
 * Loaded by every web page AND by the scanner (CLI):
 *   require_once __DIR__ . '/config.php';
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Symfony\Component\Dotenv\Dotenv;

/* -------------------------------------------------------------------------
 * 1. Environment
 * ---------------------------------------------------------------------- */

if (is_file(__DIR__ . '/.env')) {
    (new Dotenv())->load(__DIR__ . '/.env');
}

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : (string) $value;
}

/** Returns the first environment variable that is set. */
function env_any(array $keys, ?string $default = null): ?string
{
    foreach ($keys as $k) {
        if (($v = env($k)) !== null) {
            return $v;
        }
    }
    return $default;
}

const IS_CLI = PHP_SAPI === 'cli';

define('APP_ENV', env('APP_ENV', 'local'));
define('APP_DEBUG', APP_ENV !== 'production');
define('APP_URL', rtrim(env('APP_URL', 'http://localhost:8000'), '/'));
define('BASE_PATH', rtrim((string) (parse_url(APP_URL, PHP_URL_PATH) ?? ''), '/'));   // e.g. "/kalshi" under XAMPP
define('APP_TIMEZONE', env('APP_TIMEZONE', 'America/New_York'));                     // display only; DB stays UTC

/* -------------------------------------------------------------------------
 * 2. PHP runtime / error handling
 * ---------------------------------------------------------------------- */

date_default_timezone_set('UTC');

ini_set('default_charset', 'UTF-8');
ini_set('memory_limit', '256M');
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$logPath = env('LOG_PATH');
if ($logPath === null) {
    $logPath = is_writable('/var/log/php') ? '/var/log/php/app-error.log' : 'php://stderr';
}
ini_set('error_log', $logPath);

ini_set('max_execution_time', IS_CLI ? '0' : '300');   // admin "Run scan now" may take a while

/* -------------------------------------------------------------------------
 * 3. Application settings
 * ---------------------------------------------------------------------- */

// Kalshi — your .env names (K_KEY, etc.) are accepted as well as the long names.
define('KALSHI_BASE_URL', rtrim(env('KALSHI_BASE_URL', 'https://external-api.kalshi.com/trade-api/v2'), '/'));
define('KALSHI_KEY_ID', env_any(['KALSHI_KEY_ID', 'K_KEY']));
$keyPath = env_any(['KALSHI_PRIVATE_KEY_PATH', 'K_KEY_PATH', 'K_PRIVATE_KEY_PATH', 'K_RSA_PATH'], 'kalshi.key');
if (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $keyPath)) {
    $keyPath = __DIR__ . '/' . preg_replace('#^\./#', '', $keyPath);   // relative paths are relative to this folder
}
define('KALSHI_PRIVATE_KEY_PATH', $keyPath);
define('KALSHI_PRIVATE_KEY_PEM', env_any(['KALSHI_PRIVATE_KEY', 'K_RSA_KEY', 'K_PRIVATE_KEY']));  // optional inline PEM
define('KALSHI_USE_AUTH', env('KALSHI_USE_AUTH', '1') === '1');     // market data is public; signing is optional
define('KALSHI_UFC_SERIES', env('KALSHI_UFC_SERIES', 'KXUFCFIGHT'));
define('KALSHI_TIMEOUT', (int) env('KALSHI_TIMEOUT', '20'));

// NewsAPI
define('NEWSAPI_KEY', env_any(['NEWSAPI_KEY', 'NEWS_API_KEY']));
define('NEWSAPI_BASE_URL', 'https://newsapi.org/v2');

// Scanner / analysis rules
define('DEFAULT_SPORT', 'UFC');
define('MOVEMENT_THRESHOLD_PP', 5.0);   // ≥ 5 percentage-point drop
define('NEWS_LOOKBACK_HOURS', 48);
define('MAX_MID_SPREAD', 0.10);         // wider bid/ask spread → fall back to last trade price

const MARKET_STATUSES      = ['open', 'closed', 'settled', 'cancelled', 'unknown'];
const SCAN_STATUSES        = ['running', 'completed', 'partial', 'failed'];
const EXPLANATION_STATUSES = ['article_found', 'no_explanation_found', 'news_search_failed'];
const ACTIVITY_TYPES       = ['login', 'market_view', 'watchlist_add', 'watchlist_remove'];
const USER_ROLES           = ['admin', 'user'];

/* -------------------------------------------------------------------------
 * 4. Database
 * ---------------------------------------------------------------------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($url = env('DATABASE_URL')) {
        $p    = parse_url($url);
        $host = $p['host'] ?? 'localhost';
        $port = $p['port'] ?? 5432;
        $name = ltrim($p['path'] ?? '', '/');
        $user = isset($p['user']) ? urldecode($p['user']) : null;
        $pass = isset($p['pass']) ? urldecode($p['pass']) : null;
    } else {
        $host = env('DB_HOST', 'localhost');
        $port = env('DB_PORT', '5432');
        $name = env('DB_NAME');
        $user = env('DB_USER');
        $pass = env('DB_PASS');
    }

    $sslmode = env('DB_SSLMODE', 'prefer');

    try {
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name;sslmode=$sslmode", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET TIME ZONE 'UTC'");
    } catch (PDOException $e) {
        error_log('[db] connection failed: ' . $e->getMessage());
        if (IS_CLI) {
            throw $e;
        }
        http_response_code(503);
        exit('Database unavailable. Check DB_* settings in .env.');
    }
    return $pdo;
}

$dbh = db();   // kept for any existing code that uses $dbh

/* -------------------------------------------------------------------------
 * 5. Kalshi RSA private key + request signing (RSA-PSS / SHA-256)
 * ---------------------------------------------------------------------- */

function kalshi_auth_available(): bool
{
    return KALSHI_USE_AUTH
        && KALSHI_KEY_ID !== null
        && (KALSHI_PRIVATE_KEY_PEM !== null || is_readable(KALSHI_PRIVATE_KEY_PATH));
}

function kalshi_private_key(): RSA\PrivateKey
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    if (KALSHI_PRIVATE_KEY_PEM !== null) {
        $pem = str_replace('\n', "\n", KALSHI_PRIVATE_KEY_PEM);   // allow single-line PEM with literal \n
    } elseif (is_readable(KALSHI_PRIVATE_KEY_PATH)) {
        $pem = file_get_contents(KALSHI_PRIVATE_KEY_PATH);
    } else {
        throw new RuntimeException('Kalshi private key not found at ' . KALSHI_PRIVATE_KEY_PATH);
    }

    $loaded = PublicKeyLoader::load($pem);
    if (!$loaded instanceof RSA\PrivateKey) {
        throw new RuntimeException('Kalshi key is not an RSA private key.');
    }

    return $key = $loaded
        ->withPadding(RSA::SIGNATURE_PSS)
        ->withHash('sha256')
        ->withMGFHash('sha256')
        ->withSaltLength(32);
}

/**
 * @param string $path Full path, e.g. /trade-api/v2/events (query string is stripped before signing)
 */
function kalshi_auth_headers(string $method, string $path): array
{
    $timestamp = (string) (int) round(microtime(true) * 1000);
    $message   = $timestamp . strtoupper($method) . explode('?', $path, 2)[0];

    return [
        'KALSHI-ACCESS-KEY: '       . KALSHI_KEY_ID,
        'KALSHI-ACCESS-TIMESTAMP: ' . $timestamp,
        'KALSHI-ACCESS-SIGNATURE: ' . base64_encode(kalshi_private_key()->sign($message)),
    ];
}

/* -------------------------------------------------------------------------
 * 6. Sessions (web only)
 * ---------------------------------------------------------------------- */

function is_loopback_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && empty($_SERVER['HTTP_X_FORWARDED_FOR']);
}

if (!IS_CLI && session_status() === PHP_SESSION_NONE) {
    $isHttps = (($_SERVER['HTTPS'] ?? '') === 'on')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '43200');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');

    session_set_cookie_params([
        'lifetime' => 43200,
        'path'     => '/',
        'secure'   => $isHttps,          // plain http://localhost would drop a "secure" cookie
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Personal localhost login from .env (kept as requested).
    // Only works for requests from 127.0.0.1/::1, so it switches itself off once deployed.
    // Set DEV_AUTO_LOGIN=0 in .env to turn it off locally.
    if (env('USER_ID') !== null && env('DEV_AUTO_LOGIN', '1') === '1'
        && is_loopback_request() && empty($_SESSION['user_id'])) {
        $_SESSION['app_pass']  = env('APP_PASS');
        $_SESSION['role']      = env('USER_ROLE', 'admin');
        $_SESSION['email']     = env('USER_EMAIL');
        $_SESSION['user_id']   = env('USER_ID');
        $_SESSION['env_login'] = true;
    }
}

/* -------------------------------------------------------------------------
 * 7. Auth helpers
 * ---------------------------------------------------------------------- */

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = (string) ($_SESSION['user_id'] ?? '');
    if ($id === '') {
        return $user = null;
    }

    $row = null;
    if (ctype_digit($id)) {
        $stmt = db()->prepare('SELECT id, email, role FROM users WHERE id = :id AND is_active = TRUE');
        $stmt->execute([':id' => (int) $id]);
        $row = $stmt->fetch() ?: null;
    }

    // .env login whose USER_ID is not (yet) a row in users: still let you in locally.
    if ($row === null && !empty($_SESSION['env_login'])) {
        $row = [
            'id'    => ctype_digit($id) ? (int) $id : 0,
            'email' => $_SESSION['email'] ?? 'local',
            'role'  => in_array($_SESSION['role'] ?? '', USER_ROLES, true) ? $_SESSION['role'] : 'admin',
        ];
    }

    if ($row === null) {
        $_SESSION = [];
        session_destroy();
    }
    return $user = $row;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('/login.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (string) $user['id'];
    unset($_SESSION['env_login']);

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $user['id']]);
    log_activity('login', (int) $user['id']);
}

function logout_user(): void
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    session_destroy();
}

/* -------------------------------------------------------------------------
 * 8. CSRF
 * ---------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(419);
        exit('Invalid or expired form. Go back, refresh, and try again.');
    }
}

/* -------------------------------------------------------------------------
 * 9. Activity log
 * ---------------------------------------------------------------------- */

function log_activity(string $type, ?int $userId = null, ?int $marketId = null, array $metadata = []): void
{
    if (!in_array($type, ACTIVITY_TYPES, true)) {
        throw new InvalidArgumentException("Unknown activity type: $type");
    }
    $userId ??= (current_user()['id'] ?? null) ?: null;
    try {
        db()->prepare('INSERT INTO activity_logs (user_id, activity_type, market_id, metadata) VALUES (:u, :t, :m, :meta)')
            ->execute([
                ':u'    => $userId,
                ':t'    => $type,
                ':m'    => $marketId,
                ':meta' => $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]);
    } catch (PDOException $e) {
        error_log('[activity] ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
 * 10. Load application classes and view helpers
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/queries.php';
require_once __DIR__ . '/src/charts.php';
require_once __DIR__ . '/src/KalshiClient.php';
require_once __DIR__ . '/src/NewsClient.php';
require_once __DIR__ . '/src/MovementAnalyzer.php';
require_once __DIR__ . '/src/Scanner.php';
