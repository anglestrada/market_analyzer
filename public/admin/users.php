<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    try {
        switch ($action) {
            case 'create':
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $pass  = (string) ($_POST['password'] ?? '');
                $role  = in_array($_POST['role'] ?? '', USER_ROLES, true) ? $_POST['role'] : 'user';
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid email address.');
                }
                if (mb_strlen($pass) < 10) {
                    throw new InvalidArgumentException('Password must be at least 10 characters.');
                }
                db()->prepare('INSERT INTO users (email, password_hash, role) VALUES (:e, :h, :r)')
                    ->execute([':e' => $email, ':h' => password_hash($pass, PASSWORD_DEFAULT), ':r' => $role]);
                flash("Account created for $email.");
                break;

            case 'toggle':
                if ($id === (int) $admin['id']) {
                    throw new InvalidArgumentException("You can't disable your own account.");
                }
                db()->prepare('UPDATE users SET is_active = NOT is_active WHERE id = :id')->execute([':id' => $id]);
                flash('Account updated.');
                break;

            case 'role':
                $role = in_array($_POST['role'] ?? '', USER_ROLES, true) ? $_POST['role'] : 'user';
                if ($id === (int) $admin['id'] && $role !== 'admin') {
                    throw new InvalidArgumentException("You can't remove your own admin role.");
                }
                db()->prepare('UPDATE users SET role = :r WHERE id = :id')->execute([':r' => $role, ':id' => $id]);
                flash('Role updated.');
                break;

            case 'password':
                $pass = (string) ($_POST['password'] ?? '');
                if (mb_strlen($pass) < 10) {
                    throw new InvalidArgumentException('Password must be at least 10 characters.');
                }
                db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                    ->execute([':h' => password_hash($pass, PASSWORD_DEFAULT), ':id' => $id]);
                flash('Password reset.');
                break;
        }
    } catch (PDOException $e) {
        flash($e->getCode() === '23505' ? 'That email already has an account.' : 'Database error.', 'error');
        error_log('[admin/users] ' . $e->getMessage());
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('/admin/users.php');
}

$users = db()->query('SELECT id, email, role, is_active, created_at, last_login_at FROM users ORDER BY created_at')->fetchAll();

page_header('Users', $admin);
?>
<h1>Users</h1>

<section class="card">
    <h2>Create account</h2>
    <form method="post" class="row-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="email" name="email" placeholder="email@example.com" required>
        <input type="password" name="password" placeholder="Temporary password (10+ chars)" minlength="10" required>
        <select name="role"><option value="user">user</option><option value="admin">admin</option></select>
        <button>Create</button>
    </form>
</section>

<section class="card">
    <table class="table">
        <thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Last login</th><th>Reset password</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['email']) ?></td>
                <td>
                    <form method="post" class="inline">
                        <?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <select name="role" onchange="this.form.submit()">
                            <?php foreach (USER_ROLES as $r): ?>
                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td><?= $u['is_active'] ? badge('active') : badge('disabled') ?></td>
                <td class="small"><?= e(fmt_time($u['created_at'], 'M j, Y')) ?></td>
                <td class="small"><?= e(fmt_time($u['last_login_at'])) ?></td>
                <td>
                    <form method="post" class="inline">
                        <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <input type="password" name="password" placeholder="new password" minlength="10" required>
                        <button>Set</button>
                    </form>
                </td>
                <td>
                    <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
                    <form method="post" class="inline">
                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button class="secondary"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php page_footer();
