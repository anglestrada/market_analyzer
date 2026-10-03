<?php
declare(strict_types=1);

/**
 * Finds a possible explanation for a price drop using TheNewsAPI.
 *
 *   movement (≥ threshold) → search TheNewsAPI, sorted by relevance
 *     → score the top 3 with the keyword rules
 *     → enough to identify what happened?  YES → save the best article
 *                                          NO  → request the next page (next 3), up to NEWS_MAX_PAGES
 *     → after the last page: save the best article that scored > 0, else "no_explanation_found"
 *
 * "Enough" = an article that mentions a fighter in the bout and whose keyword score is ≥ NEWS_CONFIDENT_SCORE.
 * Scoring is deterministic and explainable: the sum of the active keyword scores found in the article text.
 */
final class MovementAnalyzer
{
    private ?array $keywords = null;

    /** Human-readable summary of the last analyze() call, for the scan log. */
    public string $lastSummary = '';

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

        $to      = new DateTimeImmutable($mv['detected_at']);
        $from    = $to->modify('-' . NEWS_LOOKBACK_HOURS . ' hours');
        $names   = $this->fighterNames($mv);
        $best    = null;
        $seen    = [];
        $checked = 0;
        $pages   = 0;
        $error   = null;

        for ($page = 1; $page <= NEWS_MAX_PAGES; $page++) {
            try {
                $result = $this->news->search($names, $from, $to, $page);
            } catch (Throwable $e) {
                $error = $e;
                break;
            }
            $pages++;

            foreach ($result['articles'] as $rank => $a) {
                if (isset($seen[$a['url']])) {
                    continue;
                }
                $seen[$a['url']] = true;
                $checked++;

                [$score, $matched] = $this->scoreArticle($a, $names);
                if ($score <= 0) {
                    continue;   // unrelated → discarded, never saved
                }
                $a['raw']['_analysis'] = ['page' => $page, 'rank' => $rank + 1, 'query' => NewsClient::orQuery($names)];
                if ($best === null
                    || $score > $best['score']
                    || ($score === $best['score'] && ($a['relevance_score'] ?? 0) > ($best['article']['relevance_score'] ?? 0))) {
                    $best = ['article' => $a, 'score' => $score, 'matched' => $matched];
                }
            }

            $enough  = $best !== null && $best['score'] >= NEWS_CONFIDENT_SCORE;
            $noMore  = count($result['articles']) < NEWS_PAGE_SIZE || $page * NEWS_PAGE_SIZE >= $result['found'];
            if ($enough || $noMore) {
                break;
            }
        }

        $where = "$checked article" . ($checked === 1 ? '' : 's') . " on $pages page" . ($pages === 1 ? '' : 's');

        // The search failed before anything useful came back → retry on a later scan.
        if ($error !== null && $best === null) {
            $this->saveAnalysis($movementId, null, 'news_search_failed', 0, [], ['names' => $names, 'error' => $error->getMessage()]);
            $this->lastSummary = 'news search failed: ' . $error->getMessage();
            throw $error;   // lets the scanner mark the run as partial
        }

        if ($best === null) {
            $this->saveAnalysis($movementId, null, 'no_explanation_found', 0, []);
            $this->lastSummary = "no explanation found ($where)";
            return 'no_explanation_found';
        }

        $articleId = $this->upsertArticle($best['article']);
        $this->saveAnalysis($movementId, $articleId, 'article_found', $best['score'], $best['matched']);
        $confidence = $best['score'] >= NEWS_CONFIDENT_SCORE ? 'strong' : 'weak';
        $this->lastSummary = sprintf('article found, score %d (%s match) after %s%s',
            $best['score'], $confidence, $where, $error ? '; a later page failed' : '');
        return 'article_found';
    }

    /** Both fighters in the bout, e.g. ["Payton Talbott", "Raul Rosas Jr."]. */
    public function fighterNames(array $mv): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT yes_subtitle FROM markets WHERE event_id = :e AND yes_subtitle IS NOT NULL');
        $stmt->execute([':e' => $mv['event_id']]);
        $names = array_column($stmt->fetchAll(), 'yes_subtitle');
        $names = array_filter(array_map('trim', $names), fn($n) =>
            mb_strlen($n) > 2 && !in_array(strtolower($n), ['yes', 'no'], true));

        if (!$names) {
            // Fallback: "332: Figueiredo vs Talbott" → ["Figueiredo", "Talbott"]
            $title = preg_replace('/^\s*\d+\s*:\s*|\s+—.*$|\b(UFC.*|Fight Night.*)$/iu', '', (string) $mv['event_title']);
            $names = array_filter(array_map('trim', preg_split('/\s+vs\.?\s+/i', $title)));
        }
        if (!$names) {
            $names = [(string) $mv['market_title']];
        }
        return array_values(array_slice(array_unique($names), 0, 6));
    }

    /**
     * Keyword score for a TheNewsAPI article. An article that doesn't mention either fighter scores 0,
     * so generic MMA news with the word "injury" isn't treated as an explanation.
     *
     * @return array{0:int,1:array<int,string>}
     */
    public function scoreArticle(array $a, array $names): array
    {
        $text = implode(' ', [$a['title'] ?? '', $a['description'] ?? '', $a['snippet'] ?? '', $a['keywords'] ?? '']);
        if ($names && !$this->mentionsFighter($text, $names)) {
            return [0, []];
        }
        return $this->score($text);
    }

    private function mentionsFighter(string $text, array $names): bool
    {
        foreach ($names as $n) {
            // Full name, or the last name on its own ("Talbott"), ignoring suffixes like "Jr."
            $parts = preg_split('/\s+/', trim(preg_replace('/\b(jr|sr|ii|iii)\.?$/i', '', $n)));
            foreach (array_unique([trim($n), (string) end($parts)]) as $needle) {
                if (mb_strlen($needle) >= 3
                    && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/iu', $text)) {
                    return true;
                }
            }
        }
        return false;
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
            ->query("SELECT keyword, score FROM keywords WHERE is_active = TRUE AND scope IN ('news', 'both') ORDER BY score DESC")
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
            ':title' => $a['title'] ?: null,
            ':descr' => ($a['description'] ?: $a['snippet']) ?: null,
            ':src'   => $a['source_name'] ?? null,
            ':pub'   => $a['published_at'] ?? null,
            ':raw'   => json_encode($a['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
