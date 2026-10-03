<?php
declare(strict_types=1);

/**
 * Shared read queries + render helpers used by several pages.
 * (PDO native prepares don't allow re-using a named parameter, so each appears once.)
 */

function latest_scan(): ?array
{
    return db()->query('SELECT * FROM scan_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
}

/**
 * @param array{market_id?:int, watch_user_id?:int, from?:string, to?:string, status?:string, side?:string} $f
 */
function fetch_movements(array $f = [], int $limit = 50): array
{
    $where  = ['e.sport = :sport'];
    $params = [':sport' => DEFAULT_SPORT];

    if (!empty($f['market_id'])) {
        $where[] = 'mm.market_id = :mid';
        $params[':mid'] = (int) $f['market_id'];
    }
    if (!empty($f['watch_user_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM watchlist_items w WHERE w.market_id = mm.market_id AND w.user_id = :wu)';
        $params[':wu'] = (int) $f['watch_user_id'];
    }
    if (!empty($f['from'])) {
        $where[] = 'mm.detected_at >= :from';
        $params[':from'] = $f['from'];
    }
    if (!empty($f['to'])) {
        $where[] = 'mm.detected_at <= :to';
        $params[':to'] = $f['to'];
    }
    if (!empty($f['status'])) {
        $where[] = "COALESCE(ma.explanation_status, 'pending') = :st";
        $params[':st'] = $f['status'];
    }
    if (!empty($f['side']) && in_array($f['side'], ['yes', 'no'], true)) {
        $where[] = 'mm.dropped_side = :side';
        $params[':side'] = $f['side'];
    }

    $sql = 'SELECT mm.*, m.market_title, m.yes_subtitle, m.no_subtitle, m.status AS market_status,
                   e.event_title,
                   ma.explanation_status, ma.relevance_score, ma.matched_keywords,
                   a.title AS article_title, a.url AS article_url, a.source_name, a.published_at AS article_published_at
              FROM market_movements mm
              JOIN markets m ON m.id = mm.market_id
              JOIN events  e ON e.id = m.event_id
         LEFT JOIN movement_analysis ma ON ma.movement_id = mm.id
         LEFT JOIN articles a ON a.id = ma.article_id
             WHERE ' . implode(' AND ', $where) . '
          ORDER BY mm.detected_at DESC
             LIMIT ' . max(1, min($limit, 1000));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function side_label(array $row): string
{
    if ($row['dropped_side'] === 'yes') {
        return 'YES' . ($row['yes_subtitle'] ? ' · ' . $row['yes_subtitle'] : '');
    }
    return 'NO' . ($row['no_subtitle'] ? ' · ' . $row['no_subtitle'] : '');
}

function safe_external_url(?string $url): ?string
{
    return ($url && preg_match('#^https?://#i', $url)) ? $url : null;
}

function render_movements_table(array $rows, bool $showMarket = true): void
{
    if (!$rows) {
        echo '<p class="muted">No movements in this range.</p>';
        return;
    }
    ?>
    <table class="table">
        <thead><tr>
            <th>Detected</th>
            <?php if ($showMarket): ?><th>Market</th><?php endif; ?>
            <th>Dropped side</th><th>Price</th><th>Drop</th><th>Possible explanation</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $kw  = json_decode((string) ($r['matched_keywords'] ?? '[]'), true) ?: [];
            $url = safe_external_url($r['article_url']);
            ?>
            <tr>
                <td class="nowrap"><?= e(fmt_time($r['detected_at'])) ?></td>
                <?php if ($showMarket): ?>
                    <td><a href="<?= e(url('/market.php', ['id' => $r['market_id']])) ?>"><?= e($r['market_title']) ?></a>
                        <div class="muted small"><?= e($r['event_title']) ?></div></td>
                <?php endif; ?>
                <td><?= e(side_label($r)) ?></td>
                <td class="nowrap"><?= fmt_price($r['previous_price']) ?> → <?= fmt_price($r['current_price']) ?></td>
                <td class="down nowrap">−<?= e(number_format((float) $r['drop_percentage_points'], 1)) ?> pts</td>
                <td>
                    <?php if ($r['explanation_status'] === 'article_found' && $url): ?>
                        <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['article_title']) ?></a>
                        <div class="muted small">
                            <?= e($r['source_name']) ?> · <?= e(fmt_time($r['article_published_at'])) ?>
                            · score <?= (int) $r['relevance_score'] ?>
                            <?php if ($kw): ?> · matched: <?= e(implode(', ', $kw)) ?><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?= badge($r['explanation_status'] ?? 'pending') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function watch_button(int $marketId, bool $watched, string $returnTo): string
{
    $action = $watched ? 'remove' : 'add';
    $label  = $watched ? '★ Watching' : '☆ Watch';
    return '<form method="post" action="' . e(url('/watchlist.php')) . '" class="inline">'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . $action . '">'
        . '<input type="hidden" name="market_id" value="' . $marketId . '">'
        . '<input type="hidden" name="return" value="' . e($returnTo) . '">'
        . '<button class="btn-watch' . ($watched ? ' on' : '') . '" title="' . ($watched ? 'Remove from' : 'Add to') . ' watchlist">'
        . $label . '</button></form>';
}
