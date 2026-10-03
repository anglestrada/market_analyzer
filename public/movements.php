<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$user = require_login();

$from   = parse_local_datetime($_GET['from'] ?? null) ?? new DateTimeImmutable('-7 days', new DateTimeZone('UTC'));
$to     = parse_local_datetime($_GET['to'] ?? null) ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
$status = in_array($_GET['status'] ?? '', [...EXPLANATION_STATUSES, 'pending'], true) ? $_GET['status'] : '';
$side   = in_array($_GET['side'] ?? '', ['yes', 'no'], true) ? $_GET['side'] : '';

$rows = fetch_movements([
    'from' => $from->format('c'), 'to' => $to->format('c'), 'status' => $status, 'side' => $side,
], 500);

page_header('Movements', $user);
?>
<h1>Movements</h1>
<p class="muted">Every time a YES or NO price dropped by at least <?= (int) MOVEMENT_THRESHOLD_PP ?> points between two consecutive scans.</p>

<form method="get" class="toolbar filters">
    <label>From <input type="datetime-local" name="from" value="<?= e(to_local_input($from)) ?>"></label>
    <label>To <input type="datetime-local" name="to" value="<?= e(to_local_input($to)) ?>"></label>
    <label>Side
        <select name="side">
            <option value="">Both</option>
            <option value="yes" <?= $side === 'yes' ? 'selected' : '' ?>>YES</option>
            <option value="no" <?= $side === 'no' ? 'selected' : '' ?>>NO</option>
        </select>
    </label>
    <label>Explanation
        <select name="status">
            <option value="">Any</option>
            <?php foreach ([...EXPLANATION_STATUSES, 'pending'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button>Filter</button>
</form>

<section class="card">
    <div class="muted small"><?= count($rows) ?> movement<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 500 ? ' (showing newest 500)' : '' ?></div>
    <?php render_movements_table($rows); ?>
</section>
<?php page_footer();
