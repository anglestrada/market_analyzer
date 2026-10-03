<?php
declare(strict_types=1);

/**
 * One scan = handoff §4 workflow:
 *  scan_run → fetch open UFC events/markets → upsert metadata → snapshot →
 *  compare with previous snapshot → movements → TheNewsAPI analysis → finish scan_run.
 */
final class Scanner
{
    private const LOCK_KEY = 74281901;   // pg advisory lock so two scans never overlap

    /** @var callable|null */
    private $log;

    public function __construct(
        private PDO $db,
        private KalshiClient $kalshi,
        private MovementAnalyzer $analyzer,
        ?callable $log = null,
    ) {
        $this->log = $log;
    }

    public static function make(?callable $log = null): self
    {
        $db = db();
        return new self($db, new KalshiClient(), new MovementAnalyzer($db, new NewsClient()), $log);
    }

    /** @return array summary of the run */
    public function run(): array
    {
        if (!$this->db->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')')->fetchColumn()) {
            $id = $this->startRun();
            $this->finishRun($id, 'failed', 0, 0, 0, 'Skipped: previous scan still running.');
            $this->say('Another scan is running; skipped.');
            return ['scan_run_id' => $id, 'status' => 'failed'];
        }

        try {
            return $this->doRun();
        } finally {
            $this->db->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
        }
    }

    private function doRun(): array
    {
        // Scans that crashed mid-way (fatal error, killed process) would stay "running" forever.
        $this->db->exec("UPDATE scan_runs SET status = 'failed', finished_at = NOW(),
                                error_message = COALESCE(error_message, 'Abandoned: process ended before finishing.')
                          WHERE status = 'running' AND started_at < NOW() - INTERVAL '30 minutes'");

        $scanId   = $this->startRun();
        $errors   = [];
        $found    = 0;
        $updated  = 0;
        $newMoves = [];
        $seen     = [];

        $this->say("Scan #$scanId started (" . ($this->kalshi->isSigned() ? 'signed' : 'public') . ' Kalshi requests).');

        // 1–2. Fetch open UFC events with nested markets
        try {
            $events = $this->kalshi->openEventsWithMarkets(KALSHI_UFC_SERIES);
        } catch (Throwable $e) {
            $this->finishRun($scanId, 'failed', 0, 0, 0, 'Kalshi fetch failed: ' . $e->getMessage());
            $this->say('FAILED: ' . $e->getMessage());
            return ['scan_run_id' => $scanId, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        // 3–7. Events, markets, snapshots, movements
        foreach ($events as $event) {
            try {
                $eventId = $this->upsertEvent($event);
            } catch (Throwable $e) {
                $errors[] = 'Event ' . ($event['event_ticker'] ?? '?') . ': ' . $e->getMessage();
                continue;
            }
            foreach ($event['markets'] ?? [] as $market) {
                $found++;
                $ticker = $market['ticker'] ?? null;
                if (!$ticker) {
                    continue;
                }
                $seen[$ticker] = true;
                try {
                    $newMoves = array_merge($newMoves, $this->processMarket($scanId, $eventId, $market));
                    $updated++;
                } catch (Throwable $e) {
                    $errors[] = "Market $ticker: " . $e->getMessage();
                }
            }
        }

        // Markets we have as open that Kalshi no longer lists as open → closed: save final snapshot.
        $stmt = $this->db->prepare(
            "SELECT m.kalshi_market_id, m.event_id FROM markets m JOIN events e ON e.id = m.event_id
              WHERE m.status = 'open' AND e.sport = :sport"
        );
        $stmt->execute([':sport' => DEFAULT_SPORT]);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($seen[$row['kalshi_market_id']])) {
                continue;
            }
            try {
                $market = $this->kalshi->market($row['kalshi_market_id']);
                $newMoves = array_merge($newMoves, $this->processMarket($scanId, (int) $row['event_id'], $market));
                $updated++;
                $this->say("Closed/updated: {$row['kalshi_market_id']} → " . KalshiClient::normalizeStatus($market['status'] ?? null));
            } catch (Throwable $e) {
                $errors[] = "Final snapshot {$row['kalshi_market_id']}: " . $e->getMessage();
            }
        }

        $this->updateEventStatuses();

        // 8–11. News analysis for new movements
        foreach ($newMoves as $movementId) {
            try {
                $result = $this->analyzer->analyze($movementId);
                $this->say("Movement #$movementId → " . ($this->analyzer->lastSummary ?: $result));
            } catch (Throwable $e) {
                $errors[] = "News for movement #$movementId: " . $e->getMessage();
            }
        }

        // Retry earlier news failures (handoff: "retried on the next scheduled scan").
        $this->retryFailedNews($errors);

        // 12–13. Finish
        $status = !$errors ? 'completed' : (($updated > 0 || $found === 0) ? 'partial' : 'failed');
        $this->finishRun($scanId, $status, $found, $updated, count($newMoves), $errors ? implode("\n", $errors) : null,
            ['series' => KALSHI_UFC_SERIES, 'events' => count($events), 'signed' => $this->kalshi->isSigned()]);

        $this->say(sprintf('Scan #%d %s: %d markets found, %d updated, %d movements, %d errors.',
            $scanId, $status, $found, $updated, count($newMoves), count($errors)));
        foreach ($errors as $err) {
            $this->say('  ! ' . $err);
        }

        return [
            'scan_run_id' => $scanId, 'status' => $status, 'markets_found' => $found,
            'markets_updated' => $updated, 'movements_detected' => count($newMoves), 'errors' => $errors,
        ];
    }

    /* ------------------------------------------------------------------ */

    private function upsertEvent(array $e): int
    {
        $title = trim(($e['title'] ?? $e['event_ticker']) . (!empty($e['sub_title']) ? ' — ' . $e['sub_title'] : ''));
        $start = self::ts($e['strike_date'] ?? null)
            ?? self::ts($e['markets'][0]['expected_expiration_time'] ?? null);

        $stmt = $this->db->prepare(
            "INSERT INTO events (kalshi_event_id, event_title, sport, event_start_time, status, raw_data)
             VALUES (:id, :title, :sport, :start, 'open', :raw)
             ON CONFLICT (kalshi_event_id) DO UPDATE
                SET event_title = EXCLUDED.event_title, event_start_time = EXCLUDED.event_start_time,
                    status = 'open', raw_data = EXCLUDED.raw_data
             RETURNING id"
        );
        $stmt->execute([
            ':id'    => $e['event_ticker'],
            ':title' => $title,
            ':sport' => DEFAULT_SPORT,
            ':start' => $start,
            ':raw'   => self::json($e),
        ]);
        return (int) $stmt->fetchColumn();
    }

    /** @return int[] new movement ids */
    private function processMarket(int $scanId, int $eventId, array $m): array
    {
        $ticker = $m['ticker'];
        $status = KalshiClient::normalizeStatus($m['status'] ?? null);
        $vals   = KalshiClient::snapshotValues($m);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id, status FROM markets WHERE kalshi_market_id = :t FOR UPDATE');
            $stmt->execute([':t' => $ticker]);
            $existing = $stmt->fetch() ?: null;

            $stmt = $this->db->prepare(
                'INSERT INTO markets (event_id, kalshi_market_id, market_ticker, market_title, yes_subtitle, no_subtitle,
                                      status, open_time, close_time, settlement_time, raw_data)
                 VALUES (:event, :id, :ticker, :title, :yes, :no, :status, :open, :close, :settle, :raw)
                 ON CONFLICT (kalshi_market_id) DO UPDATE
                    SET event_id = EXCLUDED.event_id, market_ticker = EXCLUDED.market_ticker,
                        market_title = EXCLUDED.market_title, yes_subtitle = EXCLUDED.yes_subtitle,
                        no_subtitle = EXCLUDED.no_subtitle, status = EXCLUDED.status,
                        open_time = EXCLUDED.open_time, close_time = EXCLUDED.close_time,
                        settlement_time = COALESCE(EXCLUDED.settlement_time, markets.settlement_time),
                        raw_data = EXCLUDED.raw_data
                 RETURNING id'
            );
            $stmt->execute([
                ':event'  => $eventId,
                ':id'     => $ticker,
                ':ticker' => $ticker,
                ':title'  => $m['title'] ?? $ticker,
                ':yes'    => ($m['yes_sub_title'] ?? '') ?: null,
                ':no'     => ($m['no_sub_title'] ?? '') ?: null,
                ':status' => $status,
                ':open'   => self::ts($m['open_time'] ?? null),
                ':close'  => self::ts($m['close_time'] ?? null),
                ':settle' => self::ts($m['settlement_ts'] ?? null)
                             ?? ($status === 'settled' ? self::ts($m['expiration_time'] ?? null) : null),
                ':raw'    => self::json($m),
            ]);
            $marketId = (int) $stmt->fetchColumn();

            $isOpen  = $status === 'open';
            $wasOpen = $existing && $existing['status'] === 'open';
            $isNew   = $existing === null;

            // Open → regular snapshot. Was open, now closed → final snapshot. Already closed → nothing.
            if (!($isOpen || $wasOpen || $isNew)) {
                $this->db->commit();
                return [];
            }

            $stmt = $this->db->prepare(
                'SELECT id, yes_price, no_price FROM market_snapshots
                  WHERE market_id = :m ORDER BY captured_at DESC, id DESC LIMIT 1'
            );
            $stmt->execute([':m' => $marketId]);
            $previous = $stmt->fetch() ?: null;

            $stmt = $this->db->prepare(
                'INSERT INTO market_snapshots (market_id, scan_run_id, yes_price, no_price, yes_bid, yes_ask, no_bid, no_ask,
                                               last_trade_price, volume, open_interest, raw_data)
                 VALUES (:m, :scan, :yp, :np, :yb, :ya, :nb, :na, :ltp, :vol, :oi, :raw)
                 ON CONFLICT (market_id, scan_run_id) DO NOTHING
                 RETURNING id'
            );
            $stmt->execute([
                ':m' => $marketId, ':scan' => $scanId,
                ':yp' => $vals['yes_price'], ':np' => $vals['no_price'],
                ':yb' => $vals['yes_bid'], ':ya' => $vals['yes_ask'],
                ':nb' => $vals['no_bid'], ':na' => $vals['no_ask'],
                ':ltp' => $vals['last_trade_price'], ':vol' => $vals['volume'], ':oi' => $vals['open_interest'],
                ':raw' => self::json($m),
            ]);
            $snapshotId = $stmt->fetchColumn();

            $movementIds = [];
            // Only open-to-open comparisons count; a settlement jump to 0/100 is not a "movement".
            if ($snapshotId && $previous && $isOpen) {
                $movementIds = $this->detectMovements($marketId, (int) $previous['id'], (int) $snapshotId, $previous, $vals);
            }

            $this->db->commit();
            return $movementIds;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Compare with the immediately previous snapshot only. One record per side per scan. */
    private function detectMovements(int $marketId, int $prevId, int $curId, array $prev, array $cur): array
    {
        $ids  = [];
        $stmt = $this->db->prepare(
            'INSERT INTO market_movements (market_id, previous_snapshot_id, current_snapshot_id, dropped_side,
                                           previous_price, current_price, drop_amount, drop_percentage_points)
             VALUES (:m, :prev, :cur, :side, :pp, :cp, :amt, :pts)
             ON CONFLICT (current_snapshot_id, dropped_side) DO NOTHING
             RETURNING id'
        );

        foreach (['yes', 'no'] as $side) {
            $p = $prev[$side . '_price'];
            $c = $cur[$side . '_price'];
            if ($p === null || $c === null) {
                continue;
            }
            $drop = round((float) $p - (float) $c, 4);
            $pts  = round($drop * 100, 2);
            if ($pts + 1e-9 < MOVEMENT_THRESHOLD_PP) {
                continue;
            }
            $stmt->execute([
                ':m' => $marketId, ':prev' => $prevId, ':cur' => $curId, ':side' => $side,
                ':pp' => $p, ':cp' => $c, ':amt' => $drop, ':pts' => $pts,
            ]);
            if ($id = $stmt->fetchColumn()) {
                $ids[] = (int) $id;
                $this->say(sprintf('  ▼ market #%d %s dropped %.1f pts (%s → %s)', $marketId, strtoupper($side), $pts, $p, $c));
            }
        }
        return $ids;
    }

    private function updateEventStatuses(): void
    {
        $this->db->prepare(
            "WITH s AS (
                SELECT e.id,
                       CASE WHEN bool_or(m.status = 'open')          THEN 'open'
                            WHEN bool_and(m.status = 'settled')      THEN 'settled'
                            WHEN bool_and(m.status = 'cancelled')    THEN 'cancelled'
                            ELSE 'closed' END AS new_status
                  FROM events e JOIN markets m ON m.event_id = e.id
                 WHERE e.sport = :sport
                 GROUP BY e.id
             )
             UPDATE events e SET status = s.new_status
               FROM s WHERE s.id = e.id AND e.status IS DISTINCT FROM s.new_status"
        )->execute([':sport' => DEFAULT_SPORT]);
    }

    private function retryFailedNews(array &$errors): void
    {
        $ids = $this->db->query(
            "SELECT movement_id FROM movement_analysis
              WHERE explanation_status = 'news_search_failed' AND analyzed_at < NOW() - INTERVAL '4 minutes'
              ORDER BY analyzed_at LIMIT 10"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            try {
                $result = $this->analyzer->analyze((int) $id);
                $this->say("Retried news for movement #$id → " . ($this->analyzer->lastSummary ?: $result));
            } catch (Throwable $e) {
                $errors[] = "News retry for movement #$id: " . $e->getMessage();
                break;   // TheNewsAPI is probably down or out of requests; try again next scan
            }
        }
    }

    /* ------------------------------------------------------------------ */

    private function startRun(): int
    {
        return (int) $this->db->query("INSERT INTO scan_runs (status) VALUES ('running') RETURNING id")->fetchColumn();
    }

    private function finishRun(int $id, string $status, int $found, int $updated, int $moves, ?string $error, ?array $raw = null): void
    {
        $this->db->prepare(
            'UPDATE scan_runs SET finished_at = NOW(), status = :s, markets_found = :f, markets_updated = :u,
                    movements_detected = :mv, error_message = :err, raw_response = :raw WHERE id = :id'
        )->execute([
            ':s' => $status, ':f' => $found, ':u' => $updated, ':mv' => $moves,
            ':err' => $error !== null ? mb_substr($error, 0, 20000) : null,
            ':raw' => $raw ? self::json($raw) : null, ':id' => $id,
        ]);
    }

    private function say(string $msg): void
    {
        if ($this->log) {
            ($this->log)($msg);
        }
    }

    private static function ts(mixed $v): ?string
    {
        if (!is_string($v) || $v === '' || str_starts_with($v, '0001-')) {
            return null;
        }
        return $v;
    }

    private static function json(array $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
