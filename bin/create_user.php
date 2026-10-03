<?php
/**
 * Create (or reset) an account from the command line — use this for your first admin.
 *
 *   php bin/create_user.php you@example.com admin
 *   php bin/create_user.php friend@example.com user
 *
 * You will be prompted for the password (hidden on Linux/macOS).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../config.php';

[$_, $email, $role] = $argv + [null, null, 'user'];

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, USER_ROLES, true)) {
    fwrite(STDERR, "Usage: php bin/create_user.php <email> [admin|user]\n");
    exit(1);
}

$hidden = DIRECTORY_SEPARATOR === '/' && posix_isatty_safe();
echo 'Password (min 10 chars): ';
if ($hidden) {
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($hidden) {
    shell_exec('stty echo');
    echo PHP_EOL;
}

if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(1);
}

$stmt = db()->prepare(
    'INSERT INTO users (email, password_hash, role) VALUES (:e, :h, :r)
     ON CONFLICT ((LOWER(email))) DO UPDATE SET password_hash = EXCLUDED.password_hash, role = EXCLUDED.role, is_active = TRUE
     RETURNING id'
);
$stmt->execute([':e' => strtolower(trim($email)), ':h' => password_hash($password, PASSWORD_DEFAULT), ':r' => $role]);
$id = $stmt->fetchColumn();

echo "Saved $role account $email (users.id = $id).\n";
echo "Tip: set USER_ID=$id in .env if you use the localhost auto-login.\n";

function posix_isatty_safe(): bool
{
    return function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
}
