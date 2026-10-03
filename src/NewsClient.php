<?php
declare(strict_types=1);

final class NewsApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly string $apiCode = '')
    {
        parent::__construct($message, $httpStatus);
    }
}

/**
 * TheNewsAPI client — GET https://api.thenewsapi.com/v1/news/all
 * Results are sorted by TheNewsAPI's relevance_score and fetched one page (3 articles on the free plan) at a time.
 */
final class NewsClient
{
    public function __construct(private ?string $token = null)
    {
        $this->token ??= NEWS_API_TOKEN;
    }

    public function isConfigured(): bool
    {
        return (bool) $this->token;
    }

    /**
     * One page of results, most relevant first.
     *
     * @param string[] $phrases e.g. ["Payton Talbott", "Raul Rosas Jr."] — any of them may match
     * @return array{articles: array<int, array>, found: int, page: int}
     */
    public function search(array $phrases, DateTimeInterface $from, DateTimeInterface $to, int $page = 1, int $limit = NEWS_PAGE_SIZE): array
    {
        if (!$this->token) {
            throw new NewsApiException('THENEWSAPI_TOKEN is not set in .env.');
        }

        $utc    = new DateTimeZone('UTC');
        $params = [
            'api_token'        => $this->token,
            'search'           => self::orQuery($phrases),
            'language'         => 'en',
            'published_after'  => DateTimeImmutable::createFromInterface($from)->setTimezone($utc)->format('Y-m-d\TH:i:s'),
            'published_before' => DateTimeImmutable::createFromInterface($to)->setTimezone($utc)->format('Y-m-d\TH:i:s'),
            'sort'             => 'relevance_score',
            'limit'            => max(1, $limit),
            'page'             => max(1, $page),
        ];

        $ch = curl_init(NEWS_API_BASE_URL . '/news/all?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'KalshiMarketAnalyzer/1.0',
        ]);
        if ($ca = env('CA_BUNDLE')) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        } elseif (defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);

        if ($body === false) {
            throw new NewsApiException("TheNewsAPI request failed: $err");
        }
        $data = json_decode((string) $body, true);
        if ($status >= 400 || !is_array($data) || isset($data['error'])) {
            $code = is_array($data) ? (string) ($data['error']['code'] ?? '') : '';
            $msg  = is_array($data) ? (string) ($data['error']['message'] ?? 'unknown error') : 'invalid JSON';
            $hint = match ($code) {
                'usage_limit_reached', 'rate_limit_reached' => ' (daily/plan limit reached; retried on a later scan)',
                'invalid_api_token'                         => ' (check THENEWSAPI_TOKEN in .env)',
                default                                     => '',
            };
            throw new NewsApiException("TheNewsAPI HTTP $status" . ($code ? " $code" : '') . ": $msg$hint", $status, $code);
        }

        $articles = [];
        foreach ($data['data'] ?? [] as $a) {
            if (empty($a['url']) || empty($a['title'])) {
                continue;
            }
            $articles[] = [
                'uuid'            => $a['uuid'] ?? null,
                'title'           => (string) $a['title'],
                'description'     => (string) ($a['description'] ?? ''),
                'snippet'         => (string) ($a['snippet'] ?? ''),
                'keywords'        => (string) ($a['keywords'] ?? ''),
                'url'             => (string) $a['url'],
                'source_name'     => $a['source'] ?? null,
                'published_at'    => $a['published_at'] ?? null,
                'relevance_score' => isset($a['relevance_score']) ? (float) $a['relevance_score'] : null,
                'raw'             => $a,
            ];
        }

        return [
            'articles' => $articles,
            'found'    => (int) ($data['meta']['found'] ?? count($articles)),
            'page'     => (int) ($data['meta']['page'] ?? $page),
        ];
    }

    /** ["Payton Talbott", "Raul Rosas"] → "Payton Talbott" | "Raul Rosas" (TheNewsAPI: | = OR, quotes = phrase). */
    public static function orQuery(array $phrases): string
    {
        $parts = [];
        foreach ($phrases as $p) {
            $p = trim(preg_replace('/["|+\-()*]/', ' ', (string) $p));
            $p = preg_replace('/\s+/', ' ', $p);
            if ($p !== '') {
                $parts[] = '"' . $p . '"';
            }
        }
        return implode(' | ', array_unique($parts));
    }
}
