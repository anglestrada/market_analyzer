<?php
declare(strict_types=1);

/**
 * X API v2 recent search (pay per use).
 * Only post fields are requested — no author expansion — because user lookups are billed separately.
 * Every call is logged in api_usage and refused once X_MONTHLY_BUDGET would be exceeded.
 */
final class XClient
{
    public function __construct(private PDO $db, private ?string $token = null, private string $baseUrl = X_API_BASE_URL)
    {
        $this->token ??= X_BEARER_TOKEN;
    }

    public function isConfigured(): bool
    {
        return (bool) $this->token;
    }

    /** USD spent this calendar month (UTC). */
    public function spentThisMonth(): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(cost_usd), 0) FROM api_usage WHERE source = 'x' AND created_at >= :start");
        $stmt->execute([':start' => gmdate('Y-m-01\T00:00:00\Z')]);
        return (float) $stmt->fetchColumn();
    }

    public function budgetLeft(): float
    {
        return max(0.0, X_MONTHLY_BUDGET - $this->spentThisMonth());
    }

    /** Would one more search (at the worst case of X_MAX_POSTS posts) stay inside the budget? */
    public function canAffordSearch(): bool
    {
        return $this->budgetLeft() + 1e-9 >= X_MAX_POSTS * X_COST_PER_POST;
    }

    /**
     * @param string[] $terms names to search for (any of them)
     * @return array<int, array{text:string, at:?string, url:string, author:?string, engagement:int}>
     */
    public function searchRecent(array $terms, DateTimeInterface $from, DateTimeInterface $to, ?int $movementId = null): array
    {
        if (!$this->token) {
            throw new HttpException('X_BEARER_TOKEN is not set in .env.');
        }
        $utc   = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $end   = DateTimeImmutable::createFromInterface($to)->setTimezone($utc);
        $now   = new DateTimeImmutable('now', $utc);
        if ($end > $now->modify('-15 seconds')) {
            $end = $now->modify('-15 seconds');            // X requires end_time ≥ 10 s in the past
        }
        if ($start < $now->modify('-6 days -23 hours')) {
            throw new HttpException('Drop is older than 7 days; X recent search cannot reach it.');
        }
        if ($start >= $end) {
            $start = $end->modify('-' . LIVE_LOOKBACK_MINUTES . ' minutes');
        }

        $query = self::query($terms);
        $data  = Http::json('GET', $this->baseUrl . '/tweets/search/recent?' . http_build_query([
            'query'        => $query,
            'start_time'   => $start->format('Y-m-d\TH:i:s\Z'),
            'end_time'     => $end->format('Y-m-d\TH:i:s\Z'),
            'max_results'  => X_MAX_POSTS,
            'sort_order'   => 'relevancy',
            'tweet.fields' => 'created_at,public_metrics,lang',
        ]), ['Authorization: Bearer ' . $this->token]);

        $posts = [];
        foreach ($data['data'] ?? [] as $t) {
            if (empty($t['id']) || !isset($t['text'])) {
                continue;
            }
            $m = $t['public_metrics'] ?? [];
            $posts[] = [
                'text'       => (string) $t['text'],
                'at'         => $t['created_at'] ?? null,
                'url'        => 'https://x.com/i/web/status/' . rawurlencode((string) $t['id']),
                'author'     => null,
                'engagement' => (int) ($m['like_count'] ?? 0) + 2 * (int) ($m['retweet_count'] ?? 0) + (int) ($m['reply_count'] ?? 0),
            ];
        }

        $this->db->prepare('INSERT INTO api_usage (source, movement_id, items, cost_usd) VALUES (\'x\', :m, :n, :c)')
            ->execute([':m' => $movementId, ':n' => count($posts), ':c' => round(count($posts) * X_COST_PER_POST, 4)]);

        return $posts;
    }

    /** ("Payton Talbott" OR Talbott OR "Raul Rosas" OR Rosas) lang:en -is:retweet */
    public static function query(array $terms): string
    {
        $parts = [];
        foreach ($terms as $t) {
            $t = trim(preg_replace('/["()]/', ' ', (string) $t));
            if ($t !== '') {
                $parts[] = str_contains($t, ' ') ? '"' . $t . '"' : $t;
            }
        }
        return '(' . implode(' OR ', array_unique($parts)) . ') lang:en -is:retweet';
    }
}
