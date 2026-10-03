<?php
declare(strict_types=1);

final class HttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }
}

/** Small cURL wrapper for the evidence sources (X, Reddit, ESPN). */
final class Http
{
    /**
     * @param string[] $headers
     * @return array decoded JSON body
     */
    public static function json(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'KalshiMarketAnalyzer/1.0',
            CURLOPT_ENCODING       => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($ca = env('CA_BUNDLE')) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        } elseif (defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        $host   = (string) parse_url($url, PHP_URL_HOST);

        if ($raw === false) {
            throw new HttpException("$host request failed: $err");
        }
        $data = json_decode((string) $raw, true);
        if ($status >= 400) {
            $msg = is_array($data)
                ? (string) ($data['detail'] ?? $data['title'] ?? $data['message'] ?? $data['error_description'] ?? $data['error'] ?? '')
                : mb_substr(trim(strip_tags((string) $raw)), 0, 200);
            throw new HttpException("$host HTTP $status" . ($msg !== '' ? ": $msg" : ''), $status);
        }
        if (!is_array($data)) {
            throw new HttpException("$host returned invalid JSON");
        }
        return $data;
    }

    /** Shortens text for a headline, on a word boundary. */
    public static function snippet(string $text, int $max = 180): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $sp  = mb_strrpos($cut, ' ');
        return rtrim($sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:-") . '…';
    }
}
