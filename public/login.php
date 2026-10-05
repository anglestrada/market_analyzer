<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

if (current_user()) {
    redirect('/index.php');
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    // Simple per-session throttle against password guessing.
    $_SESSION['login_fails'] ??= 0;
    if ($_SESSION['login_fails'] >= 5 && (time() - ($_SESSION['login_last_fail'] ?? 0)) < 60) {
        $error = 'Too many attempts. Wait a minute and try again.';
    } else {
        $stmt = db()->prepare('SELECT id, email, role, password_hash, is_active FROM users WHERE LOWER(email) = :e');
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch();

        // Verify against a dummy hash when the user doesn't exist so timing doesn't reveal valid emails.
        $hash = $user['password_hash'] ?? '$2y$12$cNXTDRGxAQCNi3bwg84AsOm/aHnPXhhllO859CvhR7V1HxYnBlcQa';
        $ok   = password_verify($password, $hash) && $user && $user['is_active'];

        if ($ok) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                    ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
            }
            unset($_SESSION['login_fails'], $_SESSION['login_last_fail']);
            login_user($user);
            redirect('/index.php');
        }

        $_SESSION['login_fails']++;
        $_SESSION['login_last_fail'] = time();
        $error = 'Invalid email or password.';
    }
}

page_header('Log in', null);
?>
<div class="card narrow auth">
    <div class="auth-mark"><?= icon('logo') ?></div>
    <h1>Welcome back</h1>
    <p class="muted">Sign in to the Kalshi Market Analyzer.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Email <input type="email" name="email" value="<?= e($email) ?>" required autofocus></label>
        <label>Password <input type="password" name="password" required></label>
        <button type="submit">Log in</button>
    </form>
    <p class="muted">Accounts are created by the site administrator.</p>
</div>
<?php page_footer();
