<?php
declare(strict_types=1);

/* ---------- output / routing ---------- */

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path, array $query = []): string
{
    return BASE_PATH . $path . ($query ? '?' . http_build_query($query) : '');
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query));
    exit;
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['msg' => $message, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- formatting ---------- */

/** 0.5500 → "55.0¢" */
function fmt_price(mixed $p): string
{
    return ($p === null || $p === '') ? '—' : number_format((float) $p * 100, 1) . '¢';
}

/** signed percentage-point change */
function fmt_pp(?float $pp): string
{
    if ($pp === null) {
        return '—';
    }
    $cls = $pp > 0 ? 'up' : ($pp < 0 ? 'down' : '');
    return '<span class="' . $cls . '">' . ($pp > 0 ? '+' : '') . number_format($pp, 1) . ' pts</span>';
}

function fmt_time(?string $ts, string $format = 'M j, Y g:i A'): string
{
    if (!$ts) {
        return '—';
    }
    return (new DateTimeImmutable($ts))->setTimezone(new DateTimeZone(APP_TIMEZONE))->format($format);
}

function fmt_int(mixed $n): string
{
    return ($n === null || $n === '') ? '—' : number_format((float) $n);
}

/** Parse a <input type="datetime-local"> value (in APP_TIMEZONE) into UTC ISO. */
function parse_local_datetime(?string $value): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone(APP_TIMEZONE)))->setTimezone(new DateTimeZone('UTC'));
    } catch (Exception) {
        return null;
    }
}

function to_local_input(DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new DateTimeZone(APP_TIMEZONE))->format('Y-m-d\TH:i');
}

function badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(str_replace('_', ' ', $status)) . '</span>';
}

/* ---------- layout ---------- */

function page_header(string $title, ?array $user = null): void
{
    $user ??= current_user();
    $flash = flash();
    $self  = $_SERVER['SCRIPT_NAME'] ?? '';
    $nav   = fn(string $path, string $label) =>
        '<a href="' . e(url($path)) . '"' . (str_ends_with($self, $path) ? ' class="active"' : '') . '>' . e($label) . '</a>';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Kalshi Market Analyzer</title>
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= e(url('/index.php')) ?>">Kalshi Market Analyzer <small>UFC</small></a>
    <?php if ($user): ?>
    <nav>
        <?= $nav('/index.php', 'Markets') ?>
        <?= $nav('/movements.php', 'Movements') ?>
        <?= $nav('/watchlist.php', 'Watchlist') ?>
        <?php if ($user['role'] === 'admin'): ?>
            <?= $nav('/admin/scans.php', 'Scans') ?>
            <?= $nav('/admin/keywords.php', 'Keywords') ?>
            <?= $nav('/admin/users.php', 'Users') ?>
        <?php endif; ?>
    </nav>
    <div class="who">
        <?= e($user['email']) ?>
        <form method="post" action="<?= e(url('/logout.php')) ?>" class="inline">
            <?= csrf_field() ?><button class="link">Log out</button>
        </form>
    </div>
    <?php endif; ?>
</header>
<main>
<?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif;
}

function page_footer(): void
{
    ?>
</main>
<footer>
    Market data analysis only. This is not a prediction or trading recommendation.
    News shown alongside a movement is a possible explanation, not a proven cause.
</footer>
</body>
</html>
<?php
}
