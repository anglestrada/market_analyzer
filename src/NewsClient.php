<?php
declare(strict_types=1);

final class NewsApiException extends RuntimeException {}

/** Minimal NewsAPI /v2/everything client. */
final class NewsClient
{
    public function __construct(private ?string $apiKey = null)
    {
        $this->apiKey ??= NEWSAPI_KEY;
    }

    /**
     * @return array<int, array> raw NewsAPI article objects
     */
    public function search(string $query, DateTimeInterface $from, DateTimeInterface $to): array
    {
        if (!$this->apiKey) {
            throw new NewsApiException('NEWSAPI_KEY is not set.');
        }

        $params = [
            'q'        => mb_substr($query, 0, 500),
            'searchIn' => 'title,description',
            'from'     => $from->format('Y-m-d\TH:i:s'),
            'to'       => $to->format('Y-m-d\TH:i:s'),
            'language' => 'en',
            'sortBy'   => 'publishedAt',
            'pageSize' => 100,
        ];

        $ch = curl_init(NEWSAPI_BASE_URL . '/everything?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $this->apiKey, 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'KalshiMarketAnalyzer/1.0',
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new NewsApiException("NewsAPI request failed: $err");
        }
        $data = json_decode((string) $body, true);
        if ($status >= 400 || !is_array($data) || ($data['status'] ?? '') !== 'ok') {
            $msg = is_array($data) ? ($data['message'] ?? 'unknown error') : 'invalid JSON';
            throw new NewsApiException("NewsAPI HTTP $status: $msg");
        }

        // NewsAPI replaces taken-down items with "[Removed]".
        return array_values(array_filter(
            $data['articles'] ?? [],
            fn($a) => !empty($a['url']) && ($a['title'] ?? '') !== '[Removed]'
        ));
    }
}
