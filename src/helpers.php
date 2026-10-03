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

/** URL for a file in public/, with a cache-busting version. */
function asset(string $path): string
{
    $file = dirname(__DIR__) . '/public' . $path;
    return url($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Uses the local copy in public/assets/vendor/ (from `npm install`) when present, otherwise the CDN. */
function vendor_script(string $file, string $cdn): string
{
    $local = dirname(__DIR__) . '/public/assets/vendor/' . $file;
    $src   = is_file($local) ? url('/assets/vendor/' . $file) . '?v=' . filemtime($local) : $cdn;
    return '<script src="' . e($src) . '"></script>' . "\n";
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

/** Embeds data for app.js charts: <canvas data-chart="..." data-source="id">. */
function json_script(string $id, mixed $data): string
{
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    return '<script type="application/json" id="' . e($id) . '">' . $json . '</script>';
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
        return '<span class="muted">—</span>';
    }
    $cls   = $pp > 0.05 ? 'up' : ($pp < -0.05 ? 'down' : 'flat');
    $arrow = $cls === 'up' ? '▲' : ($cls === 'down' ? '▼' : '•');
    return '<span class="delta ' . $cls . '">' . $arrow . ' ' . number_format(abs($pp), 1) . '</span>';
}

function fmt_time(?string $ts, string $format = 'M j, Y g:i A'): string
{
    if (!$ts) {
        return '—';
    }
    return (new DateTimeImmutable($ts))->setTimezone(new DateTimeZone(APP_TIMEZONE))->format($format);
}

function iso(?string $ts): ?string
{
    return $ts ? (new DateTimeImmutable($ts))->format(DATE_ATOM) : null;
}

/** <time> that app.js turns into "5 minutes ago" / "in 3 days" (absolute time on hover). */
function reltime(?string $ts, string $format = 'M j, Y g:i A'): string
{
    if (!$ts) {
        return '<span class="muted">—</span>';
    }
    $abs = fmt_time($ts, $format);
    return '<time datetime="' . e(iso($ts)) . '" data-reltime title="' . e($abs) . '">' . e($abs) . '</time>';
}

function fmt_int(mixed $n): string
{
    return ($n === null || $n === '') ? '—' : number_format((float) $n);
}

/** Number that counts up on page load. */
function count_num(float|int|null $n, int $decimals = 0, string $suffix = ''): string
{
    if ($n === null) {
        return '—';
    }
    return '<span data-count="' . e((string) $n) . '" data-decimals="' . $decimals . '" data-suffix="' . e($suffix) . '">'
        . e(number_format((float) $n, $decimals) . $suffix) . '</span>';
}

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

/* ---------- visual building blocks ---------- */

function icon(string $name, string $class = ''): string
{
    static $paths = [
        'logo'      => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/><path d="M4 10l6-6 6 9 5-5"/>',
        'markets'   => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'movements' => '<path d="M3 7l6 6 4-4 8 8"/><path d="M21 10v7h-7"/>',
        'star'      => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'scans'     => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'keywords'  => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'users'     => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 4a4 4 0 0 1 0 8"/><path d="M22 21a7 7 0 0 0-5-6.7"/>',
        'theme'     => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'zoom'      => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'news'      => '<path d="M4 4h13v16H6a2 2 0 0 1-2-2z"/><path d="M17 8h3v10a2 2 0 0 1-2 2"/><path d="M8 8h5M8 12h5M8 16h3"/>',
    ];
    return '<svg class="ic ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Tiny inline SVG line chart — no JavaScript, so hundreds of them stay fast. */
function sparkline_svg(array $values, int $w = 120, int $h = 34): string
{
    $values = array_values(array_filter($values, fn($v) => $v !== null));
    if (count($values) < 2) {
        return '<span class="spark-empty">—</span>';
    }
    $min = min($values);
    $max = max($values);
    if ($max - $min < 0.01) {          // flat line: draw it in the middle
        $min -= 0.01;
        $max += 0.01;
    }
    $n   = count($values);
    $pts = [];
    foreach ($values as $i => $v) {
        $x     = round(1 + $i * ($w - 2) / ($n - 1), 1);
        $y     = round(3 + (1 - ($v - $min) / ($max - $min)) * ($h - 6), 1);
        $pts[] = "$x,$y";
    }
    $trend = end($values) <=> $values[0];
    $cls   = $trend > 0 ? 'spark-up' : ($trend < 0 ? 'spark-down' : 'spark-flat');
    $line  = implode(' ', $pts);
    [$lx, $ly] = explode(',', end($pts));

    return '<svg class="spark ' . $cls . '" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<polygon class="spark-area" points="1,' . $h . ' ' . $line . ' ' . ($w - 1) . ',' . $h . '"/>'
        . '<polyline class="spark-line" points="' . $line . '"/>'
        . '<circle class="spark-dot" cx="' . $lx . '" cy="' . $ly . '" r="2.4"/></svg>';
}

function prob_bar(mixed $yes): string
{
    $pct = $yes === null ? 0 : max(0, min(100, (float) $yes * 100));
    return '<div class="prob" title="' . e(number_format($pct, 1)) . '% implied"><span style="width:' . round($pct, 1) . '%"></span></div>';
}

function kpi(string $label, string $valueHtml, string $subHtml = '', string $tone = 'accent', string $icon = 'markets'): string
{
    return '<div class="kpi kpi-' . e($tone) . '">'
        . '<div class="kpi-icon">' . icon($icon) . '</div>'
        . '<div><span class="kpi-label">' . e($label) . '</span>'
        . '<b class="kpi-value">' . $valueHtml . '</b>'
        . '<span class="kpi-sub">' . $subHtml . '</span></div></div>';
}

/* ---------- layout ---------- */

function page_header(string $title, ?array $user = null, array $opts = []): void
{
    $user ??= current_user();
    $flash = flash();
    $self  = $_SERVER['SCRIPT_NAME'] ?? '';
    $nav   = fn(string $path, string $label, string $ic) =>
        '<a href="' . e(url($path)) . '"' . (str_ends_with($self, $path) ? ' class="active"' : '') . '>' . icon($ic) . '<span>' . e($label) . '</span></a>';
    $scan  = $user ? latest_scan() : null;
    $stale = $scan && strtotime($scan['started_at']) < time() - 15 * 60;
    $scanTone = !$scan ? 'none' : ($stale ? 'stale' : $scan['status']);
    ?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Kalshi Market Analyzer</title>
    <script>document.documentElement.dataset.theme = localStorage.getItem('kma-theme') || 'dark';</script>
    <link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
</head>
<body<?= !empty($opts['autorefresh']) ? ' data-autorefresh="' . (int) $opts['autorefresh'] . '"' : '' ?>>
<header class="topbar">
    <a class="brand" href="<?= e(url('/index.php')) ?>">
        <span class="brand-mark"><?= icon('logo') ?></span>
        <span>Market Analyzer <small>UFC</small></span>
    </a>
    <?php if ($user): ?>
    <nav>
        <?= $nav('/index.php', 'Dashboard', 'markets') ?>
        <?= $nav('/movements.php', 'Movements', 'movements') ?>
        <?= $nav('/watchlist.php', 'Watchlist', 'star') ?>
        <?php if ($user['role'] === 'admin'): ?>
            <span class="nav-sep"></span>
            <?= $nav('/admin/scans.php', 'Scans', 'scans') ?>
            <?= $nav('/admin/keywords.php', 'Keywords', 'keywords') ?>
            <?= $nav('/admin/users.php', 'Users', 'users') ?>
        <?php endif; ?>
    </nav>
    <div class="top-right">
        <span class="scan-pill scan-<?= e($scanTone) ?>" title="Latest scan">
            <i></i><?= $scan ? 'Scanned ' . reltime($scan['started_at']) : 'No scans yet' ?>
        </span>
        <button type="button" class="icon-btn" data-theme-toggle title="Toggle light/dark"><?= icon('theme') ?></button>
        <span class="who"><?= e($user['email']) ?></span>
        <form method="post" action="<?= e(url('/logout.php')) ?>" class="inline">
            <?= csrf_field() ?><button class="icon-btn" title="Log out"><?= icon('logout') ?></button>
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
<?= vendor_script('chart.umd.js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.js') ?>
<?= vendor_script('hammer.min.js', 'https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js') ?>
<?= vendor_script('chartjs-plugin-zoom.min.js', 'https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js') ?>
<?= vendor_script('chartjs-adapter-date-fns.bundle.min.js', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js') ?>
<script src="<?= e(asset('/assets/app.js')) ?>"></script>
</body>
</html>
<?php
}
