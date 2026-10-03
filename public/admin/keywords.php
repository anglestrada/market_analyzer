<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    try {
        $keyword  = trim((string) ($_POST['keyword'] ?? ''));
        $score    = (int) ($_POST['score'] ?? 0);
        $category = trim((string) ($_POST['category'] ?? '')) ?: null;
        $scope    = in_array($_POST['scope'] ?? '', ['news', 'live', 'both'], true) ? $_POST['scope'] : 'news';
        $polarity = in_array($_POST['polarity'] ?? '', ['good', 'bad', 'neutral'], true) ? $_POST['polarity'] : 'neutral';

        if (in_array($action, ['create', 'update'], true)) {
            if ($scope !== 'news' && $polarity === 'neutral') {
                throw new InvalidArgumentException('Live keywords need a polarity (good or bad for the fighter), otherwise they never count.');
            }
            if ($keyword === '' || mb_strlen($keyword) > 100) {
                throw new InvalidArgumentException('Keyword must be 1–100 characters.');
            }
            if ($score < 1 || $score > 100) {
                throw new InvalidArgumentException('Score must be between 1 and 100.');
            }
        }

        switch ($action) {
            case 'create':
                db()->prepare('INSERT INTO keywords (keyword, score, category, scope, polarity) VALUES (:k, :s, :c, :sc, :p)')
                    ->execute([':k' => $keyword, ':s' => $score, ':c' => $category, ':sc' => $scope, ':p' => $polarity]);
                flash("Added “{$keyword}”.");
                break;
            case 'update':
                db()->prepare('UPDATE keywords SET keyword = :k, score = :s, category = :c, scope = :sc, polarity = :p, is_active = :a WHERE id = :id')
                    ->execute([':k' => $keyword, ':s' => $score, ':c' => $category, ':sc' => $scope, ':p' => $polarity,
                               ':a' => isset($_POST['is_active']) ? 'true' : 'false', ':id' => $id]);
                flash("Saved “{$keyword}”.");
                break;
            case 'delete':
                db()->prepare('DELETE FROM keywords WHERE id = :id')->execute([':id' => $id]);
                flash('Keyword deleted.');
                break;
        }
    } catch (PDOException $e) {
        flash($e->getCode() === '23505' ? 'That keyword already exists.' : 'Database error.', 'error');
        error_log('[admin/keywords] ' . $e->getMessage());
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('/admin/keywords.php');
}

$keywords = db()->query("SELECT * FROM keywords
                           ORDER BY is_active DESC, CASE scope WHEN 'news' THEN 0 WHEN 'both' THEN 1 ELSE 2 END,
                                    polarity, category NULLS LAST, score DESC, keyword")->fetchAll();

$select = function (string $name, array $options, string $value, ?string $form = null): string {
    $html = '<select name="' . e($name) . '"' . ($form ? ' form="' . e($form) . '"' : '') . '>';
    foreach ($options as $v => $label) {
        $html .= '<option value="' . e($v) . '"' . ($v === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select>';
};
$scopes     = ['news' => 'News articles', 'live' => 'Live posts (X / Reddit)', 'both' => 'Both'];
$polarities = ['neutral' => 'Neutral', 'good' => 'Good for fighter', 'bad' => 'Bad for fighter'];

$freq = chart_keyword_freq(null, null, 1000);
$hits = array_change_key_case(array_combine($freq['labels'], $freq['values']) ?: [], CASE_LOWER);
$top  = ['labels' => array_slice($freq['labels'], 0, 12), 'values' => array_slice($freq['values'], 0, 12)];

// Quick tester: paste a headline and see how it scores.
$test = trim((string) ($_GET['test'] ?? ''));
$testResult = $test !== '' ? (new MovementAnalyzer(db(), new NewsClient()))->score($test) : null;

page_header('Keywords', $admin);
?>
<h1>Keyword rules</h1>
<p class="muted">An article's score is the sum of the scores of every active <b>news</b> keyword found in its title or description
    (whole-word, case-insensitive). Articles scoring 0 are discarded; only the highest-scoring article is saved for a movement.</p>
<p class="muted"><b>Live</b> keywords score X and Reddit posts. Their polarity is for the fighter the post is about
    (the closest name before the phrase): “Talbott looks sharp” is good for Talbott, so it fits a drop in his opponent's price,
    not his own. A post only counts when it fits the direction of the drop.</p>

<section class="card">
    <h2>Add keyword</h2>
    <form method="post" class="row-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <input name="keyword" placeholder="e.g. torn ACL" required maxlength="100">
        <input type="number" name="score" placeholder="score" min="1" max="100" value="5" required>
        <input name="category" placeholder="category (health, status…)">
        <?= $select('scope', $scopes, 'news') ?>
        <?= $select('polarity', $polarities, 'neutral') ?>
        <button>Add</button>
    </form>
</section>

<section class="card">
    <h2>Test a headline</h2>
    <form method="get" class="row-form">
        <input name="test" value="<?= e($test) ?>" placeholder="Paste an article title or description" style="flex:1">
        <button>Score</button>
    </form>
    <?php if ($testResult): ?>
        <p>Score <b><?= (int) $testResult[0] ?></b><?= $testResult[1] ? ' — matched: ' . e(implode(', ', $testResult[1])) : ' — no matches (article would be discarded)' ?></p>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head"><h2>Keyword hits</h2><span class="muted small">how often each keyword appeared in a saved explanation (all time)</span></div>
    <div class="chart-box h-260"><canvas data-chart="keywords" data-source="d-kw" data-empty="No explanations saved yet."></canvas></div>
    <?= json_script('d-kw', $top['labels'] ? $top : null) ?>
</section>

<section class="card">
    <table class="table">
        <thead><tr><th>Keyword</th><th>Score</th><th>Category</th><th>Used for</th><th>Polarity</th><th>Active</th><th>Hits</th><th></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($keywords as $k): $form = 'kw' . (int) $k['id']; ?>
            <tr class="<?= $k['is_active'] ? '' : 'inactive' ?>">
                <td><input form="<?= $form ?>" name="keyword" value="<?= e($k['keyword']) ?>" required maxlength="100"></td>
                <td><input form="<?= $form ?>" type="number" name="score" value="<?= (int) $k['score'] ?>" min="1" max="100" class="num"></td>
                <td><input form="<?= $form ?>" name="category" value="<?= e($k['category']) ?>"></td>
                <td><?= $select('scope', $scopes, (string) $k['scope'], $form) ?></td>
                <td><?= $select('polarity', $polarities, (string) $k['polarity'], $form) ?></td>
                <td><input form="<?= $form ?>" type="checkbox" name="is_active" <?= $k['is_active'] ? 'checked' : '' ?>></td>
                <td><?= (int) ($hits[strtolower($k['keyword'])] ?? 0) ?></td>
                <td>
                    <form method="post" id="<?= $form ?>" class="inline">
                        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                        <button>Save</button>
                    </form>
                </td>
                <td>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this keyword?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                        <button class="secondary">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php page_footer();
