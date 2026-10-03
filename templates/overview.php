<?php
/**
 * Overview page body. Expects $vm from public/index.php:
 *   markets, summary, drops, selected, initial, range, scan, latest, is_admin, preview
 * Pure presentation: no database calls in here.
 */
declare(strict_types=1);

$markets  = $vm['markets'];
$summary  = $vm['summary'];
$drops    = $vm['drops'];
$scan     = $vm['scan'];
$latest   = $vm['latest'];
$scanAt   = $scan ? ($scan['finished_at'] ?? $scan['started_at']) : null;
$failing  = $latest && in_array($latest['status'], ['failed', 'partial'], true)
    && (!$scan || (int) $latest['id'] > (int) $scan['id']);
$events   = count(array_unique(array_column($markets, 'event')));

$newsLabel = [
    'article_found'        => ['Article found', 'ok'],
    'no_explanation_found' => ['No explanation found', 'muted'],
    'news_search_failed'   => ['Search failed', 'warn'],
    'pending'              => ['Analysis pending', 'info'],
];
$newsCell = function (?string $status) use ($newsLabel): string {
    if ($status === null) {
        return '<span class="status status-none">No drop</span>';
    }
    [$label, $tone] = $newsLabel[$status] ?? [$status, 'muted'];
    return '<span class="status status-' . $tone . '">' . e($label) . '</span>';
};
$cents = fn(?float $c) => $c === null ? '—' : number_format($c, $c == floor($c) ? 0 : 1) . '¢';
?>
<?php if ($vm['preview']): ?>
    <div class="preview-banner" role="note"><b>Preview data.</b> Every market, price and article on this page is sample data generated for a design preview. It is not from Kalshi or NewsAPI.</div>
<?php endif; ?>

<header class="page-head">
    <div>
        <p class="eyebrow">UFC / Market research</p>
        <h1>Overview</h1>
        <p class="page-sub">Price changes, price history, and possible explanations from the news.</p>
    </div>
    <div class="scan-box<?= $failing ? ' is-warn' : '' ?>">
        <span class="scan-box-label">Last successful scan</span>
        <?php if ($scanAt): ?>
            <b class="scan-box-time"><?= e(fmt_time($scanAt, 'g:i A')) ?></b>
            <span class="scan-box-sub"><?= e(fmt_time($scanAt, 'M j')) ?> · <?= reltime($scanAt) ?> · every 5 minutes</span>
        <?php else: ?>
            <b class="scan-box-time">—</b>
            <span class="scan-box-sub">No completed scan yet</span>
        <?php endif; ?>
        <?php if ($failing): ?>
            <span class="scan-box-warn">Latest scan <?= e($latest['status']) ?> <?= reltime($latest['started_at']) ?><?php if ($vm['is_admin']): ?> · <a href="<?= e(url('/admin/scans.php')) ?>">details</a><?php endif; ?></span>
        <?php endif; ?>
    </div>
</header>

<section class="summary" aria-label="Summary">
    <?= kpi('Open markets', e((string) count($markets)), e((string) $events) . ' fight' . ($events === 1 ? '' : 's')) ?>
    <?= kpi('Significant movements', e((string) $summary['moves_24h']),
        'drops of at least ' . (int) MOVEMENT_THRESHOLD_PP . ' pp · last 24 h · ' . e((string) $summary['explained_24h']) . ' with an article') ?>
    <?= kpi('Watched markets', e((string) $summary['watched']),
        '<a href="' . e(url('/watchlist.php')) . '">' . e((string) $summary['watched_open']) . ' open · view watchlist</a>') ?>
</section>

<?php if (!$markets && !$vm['initial']): ?>
    <section class="panel empty-state">
        <?= icon('markets') ?>
        <h2>No open UFC markets yet</h2>
        <p>The scanner hasn't saved any open markets. Run <code>php cron/scan.php</code> (or <b>Scans → Run scan now</b>) and this page fills in automatically.</p>
    </section>
<?php else: ?>

<section class="panel overview" id="overview"
         data-api="<?= e(url('/api/market.php')) ?>"
         data-tz="<?= e(APP_TIMEZONE) ?>"
         data-csrf="<?= e(csrf_token()) ?>"
         data-watch-action="<?= e(url('/watchlist.php')) ?>"
         data-range="<?= e($vm['range']) ?>"
         data-threshold="<?= e((string) MOVEMENT_THRESHOLD_PP) ?>">

    <aside class="market-watch" aria-label="Open markets">
        <div class="mw-head">
            <h2>Market watch</h2>
            <span class="count" title="Open markets"><?= count($markets) ?></span>
        </div>
        <label class="mw-search">
            <?= icon('search') ?>
            <input type="search" placeholder="Filter fighters" aria-label="Filter markets" data-filter=".mw-item" data-empty="#mw-none">
        </label>
        <p class="mw-hint">Change = YES since the previous scan</p>
        <div class="mw-list" role="list">
            <?php $lastEvent = null; foreach ($markets as $m): ?>
                <?php if ($m['event'] !== $lastEvent): $lastEvent = $m['event']; ?>
                    <div class="mw-group"><?= e($m['event']) ?></div>
                <?php endif; ?>
                <a class="mw-item<?= $m['id'] === $vm['selected'] ? ' is-selected' : '' ?>" role="listitem"
                   href="<?= e(url('/index.php', ['market' => $m['id']])) ?>" data-market-id="<?= $m['id'] ?>"
                   data-search="<?= e(strtolower($m['name'] . ' ' . $m['title'] . ' ' . $m['event'])) ?>"
                   <?= $m['id'] === $vm['selected'] ? 'aria-current="true"' : '' ?>>
                    <span class="mw-name"><?= e($m['name']) ?><?php if ($m['watched']): ?> <span title="On your watchlist"><?= icon('star', 'mw-star') ?></span><?php endif; ?></span>
                    <?= fmt_pp($m['change']) ?>
                    <span class="mw-prices num">YES <?= $cents($m['yes']) ?> · NO <?= $cents($m['no']) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (!$markets): ?><p class="mw-empty">No open markets. Showing a closed market.</p><?php endif; ?>
            <p class="mw-empty" id="mw-none" hidden>No markets match that filter.</p>
        </div>
    </aside>

    <div class="selected" data-el="selected" aria-live="polite">
        <div class="sel-head">
            <div class="sel-title">
                <p class="eyebrow" data-el="eyebrow">Selected market</p>
                <h2 data-el="name"><span class="skeleton w-40"></span></h2>
                <p class="sel-event" data-el="event"></p>
            </div>
            <div class="sel-actions" data-el="actions"></div>
        </div>

        <div class="sel-price-row">
            <div class="sel-price">
                <b class="price-big num" data-el="price"><span class="skeleton w-20"></span></b>
                <span class="price-side" data-el="price-side"></span>
                <p class="price-change" data-el="change"></p>
            </div>
            <div class="sel-controls">
                <div class="seg" role="group" aria-label="Side" data-el="sides">
                    <button type="button" data-side="yes" aria-pressed="true">YES</button>
                    <button type="button" data-side="no" aria-pressed="false">NO</button>
                    <button type="button" data-side="both" aria-pressed="false">Both</button>
                </div>
                <div class="seg" role="group" aria-label="Date range" data-el="ranges">
                    <?php foreach (OVERVIEW_RANGES as $key => [, $label]): ?>
                        <button type="button" data-range="<?= e($key) ?>" aria-pressed="<?= $key === $vm['range'] ? 'true' : 'false' ?>" title="<?= e($label) ?>"><?= e($key === 'all' ? 'All' : $key) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="chart-area">
            <canvas id="overview-chart" aria-label="Price history chart" role="img"></canvas>
            <div class="chart-state" data-el="chart-state" hidden></div>
        </div>
        <div class="chart-foot">
            <span data-el="foot-left">Price history · price in cents</span>
            <span class="num" data-el="foot-right"></span>
        </div>
    </div>

    <aside class="news" data-el="news" aria-live="polite">
        <p class="eyebrow">News context</p>
        <div class="news-body" data-el="news-body">
            <span class="skeleton w-80"></span><span class="skeleton w-60"></span><span class="skeleton w-40"></span>
        </div>
    </aside>

    <?= json_script('overview-initial', $vm['initial']) ?>
    <noscript><p class="chart-state">Turn on JavaScript to see the price chart and news context.</p></noscript>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Latest drops</h2>
        <span class="panel-note">Drops of at least <?= (int) MOVEMENT_THRESHOLD_PP ?> pp between the previous scan and the current scan · last 7 days</span>
    </div>
    <?php if (!$drops): ?>
        <div class="empty-inline">
            <?= icon('movements') ?>
            <div><b>No qualifying drops in the last 7 days.</b>
                <p>A row appears here when YES or NO falls by <?= (int) MOVEMENT_THRESHOLD_PP ?> percentage points or more between two scans.</p></div>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table data-table drops-table">
            <thead><tr>
                <th>Market</th>
                <th class="num">YES</th>
                <th class="num col-no">NO</th>
                <th class="num">Drop</th>
                <th class="num col-detected">Detected</th>
                <th class="col-news">News</th>
            </tr></thead>
            <tbody>
            <?php foreach ($drops as $d): $mid = (int) $d['market_id']; ?>
                <tr data-market-id="<?= $mid ?>" class="<?= $mid === $vm['selected'] ? 'is-selected' : '' ?>">
                    <td>
                        <a href="<?= e(url('/index.php', ['market' => $mid])) ?>" data-market-id="<?= $mid ?>"><?= e($d['yes_subtitle'] ?: $d['market_title']) ?></a>
                        <span class="cell-sub"><?= e($d['event_title']) ?></span>
                    </td>
                    <td class="num"><?= $cents($d['yes_after']) ?></td>
                    <td class="num col-no"><?= $cents($d['no_after']) ?></td>
                    <td class="num">
                        <span class="side side-<?= e($d['dropped_side']) ?>"><?= strtoupper(e($d['dropped_side'])) ?></span>
                        <span class="delta down" title="<?= e(strtoupper($d['dropped_side'])) ?> fell <?= e(number_format((float) $d['drop_percentage_points'], 1)) ?> percentage points">−<?= e(number_format((float) $d['drop_percentage_points'], 1)) ?> pp</span>
                    </td>
                    <td class="num col-detected"><?= e(fmt_time($d['detected_at'], 'M j, g:i A')) ?></td>
                    <td class="col-news"><?= $newsCell($d['explanation_status'] ?? 'pending') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
