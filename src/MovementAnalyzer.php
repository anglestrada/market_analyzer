<?php
declare(strict_types=1);

/**
 * Searches NewsAPI for a movement and stores the single best-scoring article.
 * Scoring is deterministic: sum of scores of active keywords found in title + description.
 */
final class MovementAnalyzer
{
    private ?array $keywords = null;

    public function __construct(private PDO $db, private NewsClient $news) {}

    /** @return string explanation_status that was saved */
    public function analyze(int $movementId): string
    {
        $stmt = $this->db->prepare(
            'SELECT mm.id, mm.detected_at, m.id AS market_id, m.event_id, m.market_title,
                    m.yes_subtitle, m.no_subtitle, e.event_title
               FROM market_movements mm
               JOIN markets m ON m.id = mm.market_id
               JOIN events  e ON e.id = m.event_id
              WHERE mm.id = :id'
        );
        $stmt->execute([':id' => $movementId]);
        $mv = $stmt->fetch();
        if (!$mv) {
            throw new RuntimeException("Movement $movementId not found");
        }

        $to    = new DateTimeImmutable($mv['detected_at']);
        $from  = $to->modify('-' . NEWS_LOOKBACK_HOURS . ' hours');
        $query = $this->buildQuery($mv);

        try {
            $articles = $this->news->search($query, $from, $to);
        } catch (Throwable $e) {
            $this->saveAnalysis($movementId, null, 'news_search_failed', 0, [], ['query' => $query, 'error' => $e->getMessage()]);
            throw $e;   // let the scanner mark the run as partial
        }

        $best = null;
        foreach ($articles as $a) {
            [$score, $matched] = $this->score(($a['title'] ?? '') . ' ' . ($a['description'] ?? ''));
            if ($score <= 0) {
                continue;   // unrelated → discarded, never saved
            }
            if ($best === null
                || $score > $best['score']
                || ($score === $best['score'] && ($a['publishedAt'] ?? '') > ($best['article']['publishedAt'] ?? ''))) {
                $best = ['article' => $a, 'score' => $score, 'matched' => $matched];
            }
        }

        if ($best === null) {
            $this->saveAnalysis($movementId, null, 'no_explanation_found', 0, []);
            return 'no_explanation_found';
        }

        $articleId = $this->upsertArticle($best['article']);
        $this->saveAnalysis($movementId, $articleId, 'article_found', $best['score'], $best['matched']);
        return 'article_found';
    }

    /** Search for both fighters in the bout, e.g. ("Islam Makhachev" OR "Jack Della Maddalena"). */
    public function buildQuery(array $mv): string
    {
        $stmt = $this->db->prepare('SELECT DISTINCT yes_subtitle FROM markets WHERE event_id = :e AND yes_subtitle IS NOT NULL');
        $stmt->execute([':e' => $mv['event_id']]);
        $names = array_column($stmt->fetchAll(), 'yes_subtitle');

        $names = array_filter(array_map('trim', $names), fn($n) =>
            mb_strlen($n) > 2 && !in_array(strtolower($n), ['yes', 'no'], true));

        if (!$names) {
            // Fallback: "Gamrot vs Ribovics" → ["Gamrot", "Ribovics"]
            $title = preg_replace('/\b(UFC.*|Fight Night.*)$/i', '', (string) $mv['event_title']);
            $names = array_filter(array_map('trim', preg_split('/\s+vs\.?\s+/i', $title)));
        }
        if (!$names) {
            $names = [(string) $mv['market_title']];
        }

        $names  = array_slice(array_unique($names), 0, 6);
        $quoted = array_map(fn($n) => '"' . str_replace('"', '', $n) . '"', $names);
        return '(' . implode(' OR ', $quoted) . ')';
    }

    /** @return array{0:int,1:array<int,string>} */
    public function score(string $text): array
    {
        $score   = 0;
        $matched = [];
        foreach ($this->activeKeywords() as $k) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($k['keyword'], '/') . '(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $text)) {
                $score    += (int) $k['score'];
                $matched[] = $k['keyword'];
            }
        }
        return [$score, $matched];
    }

    private function activeKeywords(): array
    {
        return $this->keywords ??= $this->db
            ->query('SELECT keyword, score FROM keywords WHERE is_active = TRUE ORDER BY score DESC')
            ->fetchAll();
    }

    private function upsertArticle(array $a): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO articles (url, title, description, source_name, published_at, raw_data)
             VALUES (:url, :title, :descr, :src, :pub, :raw)
             ON CONFLICT (url) DO UPDATE SET title = EXCLUDED.title   -- no-op update so RETURNING works
             RETURNING id'
        );
        $stmt->execute([
            ':url'   => $a['url'],
            ':title' => $a['title'] ?? null,
            ':descr' => $a['description'] ?? null,
            ':src'   => $a['source']['name'] ?? null,
            ':pub'   => $a['publishedAt'] ?? null,
            ':raw'   => json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function saveAnalysis(int $movementId, ?int $articleId, string $status, int $score, array $matched, array $extra = []): void
    {
        if ($extra) {
            error_log('[news] movement ' . $movementId . ': ' . json_encode($extra));
        }
        $this->db->prepare(
            'INSERT INTO movement_analysis (movement_id, article_id, explanation_status, relevance_score, matched_keywords)
             VALUES (:m, :a, :s, :score, :kw)
             ON CONFLICT (movement_id) DO UPDATE
                SET article_id = EXCLUDED.article_id, explanation_status = EXCLUDED.explanation_status,
                    relevance_score = EXCLUDED.relevance_score, matched_keywords = EXCLUDED.matched_keywords,
                    analyzed_at = NOW()'
        )->execute([
            ':m'     => $movementId,
            ':a'     => $articleId,
            ':s'     => $status,
            ':score' => $score,
            ':kw'    => json_encode(array_values($matched)),
        ]);
    }
}
