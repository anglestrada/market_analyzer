<?php
declare(strict_types=1);

final class KalshiApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }
}

/**
 * Thin Kalshi Trade API v2 client (read-only).
 * Market-data endpoints are public; if a key is configured, requests are signed anyway.
 */
final class KalshiClient
{
    private string $baseUrl;
    private string $basePath;   // e.g. /trade-api/v2 — part of the signed path
    private bool $signed;

    public function __construct(?string $baseUrl = null, ?bool $signed = null)
    {
        $this->baseUrl  = rtrim($baseUrl ?? KALSHI_BASE_URL, '/');
        $this->basePath = rtrim((string) parse_url($this->baseUrl, PHP_URL_PATH), '/');
        $this->signed   = $signed ?? kalshi_auth_available();
    }

    public function isSigned(): bool
    {
        return $this->signed;
    }

    public function get(string $endpoint, array $query = []): array
    {
        $url = $this->baseUrl . $endpoint . ($query ? '?' . http_build_query($query) : '');

        for ($attempt = 1; ; $attempt++) {
            $headers = ['Accept: application/json'];
            if ($this->signed) {
                $headers = array_merge($headers, kalshi_auth_headers('GET', $this->basePath . $endpoint));
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => KALSHI_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => 'KalshiMarketAnalyzer/1.0',
            ]);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            // Retry rate limits and transient 5xx a couple of times within the same scan.
            if (($status === 429 || $status >= 500 || $body === false) && $attempt < 3) {
                usleep(500_000 * $attempt);
                continue;
            }
            break;
        }

        if ($body === false) {
            throw new KalshiApiException("Kalshi request failed: $err ($endpoint)");
        }
        if ($status >= 400) {
            throw new KalshiApiException("Kalshi HTTP $status on $endpoint: " . mb_substr((string) $body, 0, 300), $status);
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new KalshiApiException("Kalshi returned invalid JSON on $endpoint");
        }
        return $data;
    }

    /** All open events in a series, with their markets nested. Handles cursor pagination. */
    public function openEventsWithMarkets(string $seriesTicker): array
    {
        $events = [];
        $cursor = null;
        $pages  = 0;

        do {
            $query = [
                'series_ticker'       => $seriesTicker,
                'status'              => 'open',
                'with_nested_markets' => 'true',
                'limit'               => 200,
            ];
            if ($cursor) {
                $query['cursor'] = $cursor;
            }
            $resp = $this->get('/events', $query);
            foreach ($resp['events'] ?? [] as $event) {
                $events[] = $event;
            }
            $cursor = $resp['cursor'] ?? '';
            $pages++;
        } while ($cursor !== '' && $cursor !== null && $pages < 50);

        return $events;
    }

    /** Single market; falls back to the historical tier for markets that rolled off the live API. */
    public function market(string $ticker): array
    {
        try {
            $resp = $this->get('/markets/' . rawurlencode($ticker));
        } catch (KalshiApiException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }
            $resp = $this->get('/historical/markets/' . rawurlencode($ticker));
        }
        if (!isset($resp['market']) || !is_array($resp['market'])) {
            throw new KalshiApiException("No market in response for $ticker");
        }
        return $resp['market'];
    }

    /* ------------------------------------------------------------------
     * Normalization: Kalshi → our columns
     * ---------------------------------------------------------------- */

    /** Maps Kalshi lifecycle states onto open/closed/settled/cancelled/unknown. */
    public static function normalizeStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'open', 'active'                                  => 'open',
            'closed', 'inactive', 'paused', 'halted'          => 'closed',
            'settled', 'determined', 'finalized', 'amended',
            'disputed'                                        => 'settled',
            'cancelled', 'canceled', 'voided', 'void'         => 'cancelled',
            default                                           => 'unknown',
        };
    }

    /** Reads a price in dollars, accepting both "yes_bid_dollars": "0.55" and legacy "yes_bid": 55 (cents). */
    public static function price(array $m, string $field): ?float
    {
        $dollars = $m[$field . '_dollars'] ?? null;
        if ($dollars !== null && $dollars !== '' && is_numeric($dollars)) {
            return self::clamp((float) $dollars);
        }
        $cents = $m[$field] ?? null;
        if ($cents !== null && $cents !== '' && is_numeric($cents)) {
            return self::clamp((float) $cents / 100);
        }
        return null;
    }

    public static function quantity(array $m, string $field): ?int
    {
        foreach ([$field . '_fp', $field] as $k) {
            if (isset($m[$k]) && is_numeric($m[$k])) {
                return max(0, (int) round((float) $m[$k]));
            }
        }
        return null;
    }

    /**
     * Snapshot values for our market_snapshots table.
     *
     * yes_price = bid/ask midpoint when the spread is ≤ MAX_MID_SPREAD,
     *             otherwise the last trade price (or the midpoint if there was never a trade).
     * no_price  = 1 − yes_price, so a drop on one side is exactly a rise on the other.
     */
    public static function snapshotValues(array $m): array
    {
        $yesBid = self::price($m, 'yes_bid');
        $yesAsk = self::price($m, 'yes_ask');
        $noBid  = self::price($m, 'no_bid');
        $noAsk  = self::price($m, 'no_ask');
        $last   = self::price($m, 'last_price');

        // Kalshi reports "no bid" as 0 and "no ask" as 1 (100¢); treat those as missing quotes.
        $bid = ($yesBid !== null && $yesBid > 0) ? $yesBid : null;
        $ask = ($yesAsk !== null && $yesAsk < 1) ? $yesAsk : null;

        $mid = ($bid !== null && $ask !== null && $ask >= $bid) ? ($bid + $ask) / 2 : null;
        $lastValid = ($last !== null && $last > 0) ? $last : null;

        if ($mid !== null && ($ask - $bid) <= MAX_MID_SPREAD + 1e-9) {
            $yes = $mid;
        } else {
            $yes = $lastValid ?? $mid;
        }

        $yes = $yes === null ? null : round($yes, 4);

        return [
            'yes_price'        => $yes,
            'no_price'         => $yes === null ? null : round(1 - $yes, 4),
            'yes_bid'          => $yesBid,
            'yes_ask'          => $yesAsk,
            'no_bid'           => $noBid,
            'no_ask'           => $noAsk,
            'last_trade_price' => $last,
            'volume'           => self::quantity($m, 'volume'),
            'open_interest'    => self::quantity($m, 'open_interest'),
        ];
    }

    private static function clamp(float $v): float
    {
        return round(max(0.0, min(1.0, $v)), 4);
    }
}
