<?php
declare(strict_types=1);

final class HttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }
}

/** Small cURL wrapper for ESPN's JSON. */
final class Http
{
    /**
     * @param string[] $headers
     * @return array decoded JSON body
     */
    /** Requests made by this PHP process, per host (shown in the scan log). */
    public static array $counts = [];

    public static function json(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $where = $host . (string) parse_url($url, PHP_URL_PATH);   // e.g. site.api.espn.com/apis/site/v2/sports/mma/ufc/news

        for ($attempt = 1; ; $attempt++) {
            $retryAfter = 0;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT      => env('HTTP_USER_AGENT', 'KalshiMarketAnalyzer/1.0'),
                CURLOPT_ENCODING       => '',
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retryAfter) {
                    if (stripos($line, 'Retry-After:') === 0) {
                        $retryAfter = (int) trim(substr($line, 12));
                    }
                    return strlen($line);
                },
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
            self::$counts[$host] = (self::$counts[$host] ?? 0) + 1;

            // One polite retry on "slow down" / server hiccups, honouring Retry-After (capped at 5 s).
            if (($status === 429 || $status >= 500 || $raw === false) && $attempt < 2) {
                sleep(max(1, min(5, $retryAfter)));
                continue;
            }
            break;
        }

        if ($raw === false) {
            throw new HttpException("$where request failed: $err");
        }
        $data = json_decode((string) $raw, true);
        if ($status >= 400) {
            $msg = is_array($data)
                ? ($data['detail'] ?? $data['title'] ?? $data['message'] ?? $data['error_description'] ?? $data['error'] ?? '')
                : mb_substr(trim(strip_tags((string) $raw)), 0, 200);
            $msg = is_string($msg) ? $msg : json_encode($msg);
            throw new HttpException("$where HTTP $status" . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : ''), $status);
        }
        if (!is_array($data)) {
            throw new HttpException("$where returned invalid JSON (HTTP $status)");
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
