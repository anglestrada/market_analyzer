<?php
declare(strict_types=1);

/**
 * Collects live evidence for a drop from ESPN, X, Reddit and Kalshi's own trades,
 * and saves the best result per source in movement_evidence (news stays in movement_analysis).
 *
 * Each source is independent: one failing or not being configured never stops the others,
 * and never fails the scan. Failed sources are retried on the next two scans.
 */
final class EvidenceCollector
{
    private const MAX_ATTEMPTS = 3;

    /** Human-readable summary of the last collect() call, for the scan log. */
    public string $lastSummary = '';

    public function __construct(
        private PDO $db,
        private KalshiClient $kalshi,
        private LiveSignals $signals,
        private ?XClient $x = null,
        private ?RedditClient $reddit = null,
        private ?EspnClient $espn = null,
    ) {}

    public static function make(PDO $db, KalshiClient $kalshi): self
    {
        return new self($db, $kalshi, new LiveSignals($db), new XClient($db), new RedditClient(), new EspnClient());
    }

    /** Sources that will be searched with the current .env. */
    public function enabledSources(): array
    {
        return array_values(array_filter(EVIDENCE_SOURCES, fn($s) => match ($s) {
            'espn'          => ESPN_ENABLED && $this->espn !== null,
            'x'             => $this->x?->isConfigured() ?? false,
            'reddit'        => $this->reddit?->isConfigured() ?? false,
            'kalshi_trades' => KALSHI_TRADES_ENABLED,
            default         => false,
        }));
    }

    /**
     * @param string[]|null $only limit to these sources (used for retries)
     * @return array<string, string> source => saved status
     */
    public function collect(int $movementId, ?array $only = null): array
    {
        $mv = $this->movement($movementId);
        if (!$mv) {
            throw new RuntimeException("Movement $movementId not found");
        }
        $ctx = FightContext::forMovement($this->db, $mv);

        $cur     = new DateTimeImmutable($mv['cur_at'] ?? $mv['detected_at']);
        $prev    = new DateTimeImmutable($mv['prev_at'] ?? $mv['detected_at']);
        $from    = $prev->modify('-' . LIVE_LOOKBACK_MINUTES . ' minutes');
        $floor   = $cur->modify('-6 hours');                 // scanner was offline → don't search a whole day of posts
        $from    = $from < $floor ? $floor : $from;

        $results = [];
        $notes   = [];
        foreach ($this->enabledSources() as $source) {
            if ($only !== null && !in_array($source, $only, true)) {
                continue;
            }
            try {
                $row = match ($source) {
                    'espn'          => $this->fromEspn($mv, $ctx, $cur),
                    'x'             => $this->fromX($mv, $ctx, $from, $cur),
                    'reddit'        => $this->signals->summarize($this->reddit->postsAbout($ctx, $from, $cur), $ctx, 'Reddit'),
                    'kalshi_trades' => $this->fromTrades($mv, $prev < $cur->modify('-24 hours') ? $cur->modify('-24 hours') : $prev, $cur),
                };
            } catch (Throwable $e) {
                $row = ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)];
                error_log("[evidence] movement $movementId $source: " . $e->getMessage());
            }
            $this->save($movementId, $source, $row);
            $results[$source] = $row['status'];
            $notes[] = SOURCE_LABELS[$source] . ' ' . $row['status']
                . ($row['status'] === 'found' ? ' (' . ($row['stance'] ?? 'neutral') . ', score ' . ($row['score'] ?? 0) . ')' : '')
                . ($row['status'] === 'failed' ? ': ' . $row['error'] : '');
        }

        $this->lastSummary = $notes ? implode('; ', $notes) : 'no live sources configured';
        return $results;
    }

    /** Re-runs sources that failed on an earlier scan (at most MAX_ATTEMPTS times each). */
    public function retryFailed(?callable $say = null, int $limit = 10): void
    {
        $rows = $this->db->query(
            "SELECT movement_id, array_agg(source) AS sources FROM movement_evidence
              WHERE status = 'failed' AND attempts < " . self::MAX_ATTEMPTS . "
                AND collected_at < NOW() - INTERVAL '4 minutes'
              GROUP BY movement_id ORDER BY MIN(collected_at) LIMIT " . max(1, $limit)
        )->fetchAll();

        foreach ($rows as $r) {
            $sources = array_filter(explode(',', trim((string) $r['sources'], '{}')));
            try {
                $this->collect((int) $r['movement_id'], $sources);
                $say && $say("Retried evidence for movement #{$r['movement_id']} → {$this->lastSummary}");
            } catch (Throwable $e) {
                $say && $say("Evidence retry for movement #{$r['movement_id']} failed: " . $e->getMessage());
            }
        }
    }

    /* ------------------------------------------------------------------ sources */

    private function fromX(array $mv, FightContext $ctx, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!$this->x->canAffordSearch()) {
            return [
                'status' => 'skipped',
                'detail' => sprintf('Monthly X budget reached ($%.2f of $%.2f spent)', $this->x->spentThisMonth(), X_MONTHLY_BUDGET),
            ];
        }
        $posts = $this->x->searchRecent($ctx->searchTerms(), $from, $to, (int) $mv['id']);
        return $this->signals->summarize($posts, $ctx, 'X');
    }

    private function fromTrades(array $mv, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $ticker = (string) ($mv['market_ticker'] ?: $mv['kalshi_market_id']);
        $trades = $this->kalshi->trades($ticker, $from->getTimestamp(), $to->getTimestamp());

        if (!$trades) {
            return [
                'status'      => 'found',
                'stance'      => 'neutral',
                'score'       => 1,
                'headline'    => 'No trades between the two scans',
                'detail'      => 'The price moved because resting orders were pulled or re-priced, not because of fills.',
                'occurred_at' => $to->format(DATE_ATOM),
            ];
        }

        $against = $mv['dropped_side'] === 'yes' ? 'no' : 'yes';   // buying NO pushes YES down, and vice versa
        $total   = 0;
        $pushing = 0;
        $fills   = 0;
        $bursts  = [];
        $first   = null;
        $last    = null;
        foreach ($trades as $t) {
            $total += $t['count'];
            if ($t['side'] !== $against) {
                continue;
            }
            $pushing += $t['count'];
            $fills++;
            $ts      = strtotime($t['at']);
            $first ??= $ts;
            $last    = $ts;
            $bursts[$ts] = ($bursts[$ts] ?? 0) + $t['count'];   // fills in the same second ≈ one order sweeping the book
        }

        $share   = $total > 0 ? $pushing / $total : 0.0;
        $side    = strtoupper($against);
        $stance  = $share >= 0.6 ? 'supports' : ($share <= 0.4 ? 'contradicts' : 'neutral');

        if ($pushing === 0) {
            return [
                'status'      => 'found',
                'stance'      => 'contradicts',
                'score'       => 1,
                'headline'    => sprintf('Only %s buying between scans (%s contracts)', strtoupper($mv['dropped_side']), number_format($total)),
                'detail'      => 'Nobody bought the other side, so trades didn\'t cause this drop.',
                'occurred_at' => $to->format(DATE_ATOM),
                'item_count'  => count($trades),
            ];
        }

        arsort($bursts);
        $bigTs   = (int) array_key_first($bursts);
        $big     = (int) reset($bursts);
        $bigShr  = $big / $pushing;
        $minutes = max(1, (int) round(($last - $first) / 60));

        $detail = [sprintf('%d%% of %s contracts traded', round($share * 100), number_format($total)), "$fills fill" . ($fills === 1 ? '' : 's')];
        if ($bigShr >= 0.4 && $big >= 100) {
            $detail[] = sprintf('mostly one large order (%s contracts at once)', number_format($big));
        } elseif ($fills >= 10 && $bigShr < 0.2) {
            $detail[] = 'spread across many traders';
        } else {
            $detail[] = sprintf('largest burst %s contracts', number_format($big));
        }

        $score = ($share >= 0.7 ? 3 : ($share >= 0.5 ? 2 : 1)) + ($total >= 1000 ? 1 : 0) + ($bigShr >= 0.4 && $big >= 100 ? 1 : 0);

        return [
            'status'      => 'found',
            'stance'      => $stance,
            'score'       => min(5, $score),
            'headline'    => sprintf('%s %s contracts bought in %s', number_format($pushing), $side,
                $fills === 1 ? 'one trade' : ($minutes === 1 ? 'about a minute' : "$minutes min")),
            'detail'      => implode(' · ', $detail),
            'occurred_at' => gmdate(DATE_ATOM, $bigTs),
            'item_count'  => $fills,
            'raw'         => ['total' => $total, 'pushing' => $pushing, 'share' => round($share, 3), 'largest_burst' => $big,
                              'first' => gmdate(DATE_ATOM, $first), 'last' => gmdate(DATE_ATOM, $last), 'trades' => count($trades)],
        ];
    }

    private function fromEspn(array $mv, FightContext $ctx, DateTimeImmutable $dropAt): array
    {
        $start = $mv['event_start_time'] ? new DateTimeImmutable($mv['event_start_time']) : null;
        $fight = $this->espn->findFight($ctx, $dropAt, $start);
        if ($fight === null) {
            return ['status' => 'none', 'detail' => 'Fight not found on ESPN\'s scoreboard'];
        }

        $lagMin = (int) round((time() - $dropAt->getTimestamp()) / 60);
        $lag    = $lagMin > 10 ? " · checked $lagMin min after the drop" : '';
        $url    = 'https://www.espn.com/mma/fightcenter';
        $base   = ['occurred_at' => gmdate(DATE_ATOM), 'url' => $url, 'raw' => $fight];

        // Which ESPN competitor is the market's fighter?
        $subj = null; $opp = null;
        foreach ($fight['fighters'] as $f) {
            if ($ctx->subject !== null && array_key_exists($ctx->subject, $ctx->mentions($f['name']))) {
                $subj = $f;
            } else {
                $opp = $f;
            }
        }
        $expected = $ctx->expectedDirection();
        $stanceOf = fn(int $dir) => ($expected === null || $dir === 0) ? 'neutral' : ($dir === $expected ? 'supports' : 'contradicts');

        if ($fight['state'] === 'pre') {
            return $base + [
                'status'   => 'found',
                'stance'   => 'neutral',
                'score'    => 1,
                'headline' => 'Fight hadn\'t started (pre-fight drop)',
                'detail'   => trim(($fight['detail'] ?: 'Scheduled') . $lag, ' ·'),
            ];
        }

        if ($fight['state'] === 'post' || $fight['completed']) {
            $winner = null;
            foreach ($fight['fighters'] as $f) {
                if ($f['winner'] === true) {
                    $winner = $f;
                }
            }
            if ($winner === null) {
                return $base + ['status' => 'found', 'stance' => 'neutral', 'score' => 3,
                    'headline' => 'Fight over: ' . ($fight['detail'] ?: 'result not posted yet'), 'detail' => ltrim($lag, ' ·')];
            }
            $loser = $winner === $subj ? $opp : $subj;
            $dir   = $subj === null ? 0 : ($winner === $subj ? 1 : -1);
            $how   = array_filter([$fight['result'], $fight['round'] ? 'R' . $fight['round'] : null, $fight['clock']]);
            $stance = $stanceOf($dir);
            return $base + [
                'status'   => 'found',
                'stance'   => $stance,
                'score'    => $stance === 'supports' ? 25 : 2,   // an official result outranks any post (posts cap at 20)
                'headline' => sprintf('Final: %s def. %s', $winner['name'], $loser['name'] ?? 'opponent'),
                'detail'   => trim(implode(' ', $how) . $lag, ' ·') ?: ($fight['detail'] ?: 'Final'),
            ];
        }

        // In progress (or between rounds).
        $headline = 'In progress: ' . ($fight['detail'] ?: trim('Round ' . ($fight['round'] ?? '?') . ' ' . ($fight['clock'] ?? '')));
        $parts    = [];
        $dir      = 0;
        if ($subj && $opp && isset($subj['stats']['sig'], $opp['stats']['sig'])) {
            $parts[] = sprintf('Sig. strikes %s %d – %d %s', self::last($subj['name']), $subj['stats']['sig'], $opp['stats']['sig'], self::last($opp['name']));
            $edge    = ($subj['stats']['sig'] - $opp['stats']['sig'])
                     + 10 * (($subj['stats']['kd'] ?? 0) - ($opp['stats']['kd'] ?? 0))
                     + 3 * (($subj['stats']['td'] ?? 0) - ($opp['stats']['td'] ?? 0));
            $dir     = abs($edge) >= 8 ? ($edge <=> 0) : 0;
            foreach (['kd' => 'Knockdowns', 'td' => 'Takedowns'] as $k => $label) {
                if (($subj['stats'][$k] ?? 0) + ($opp['stats'][$k] ?? 0) > 0) {
                    $parts[] = sprintf('%s %d – %d', $label, $subj['stats'][$k] ?? 0, $opp['stats'][$k] ?? 0);
                }
            }
            $parts[0] .= ' (fight totals)';
        }
        $stance = $stanceOf($dir);
        return $base + [
            'status'   => 'found',
            'stance'   => $stance,
            'score'    => 5 + ($stance === 'supports' ? 3 : 0),
            'headline' => $headline,
            'detail'   => trim(implode(' · ', $parts) . $lag, ' ·') ?: 'Live fight',
        ];
    }

    /* ------------------------------------------------------------------ storage */

    private function movement(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT mm.id, mm.market_id, mm.dropped_side, mm.detected_at,
                    ps.captured_at AS prev_at, cs.captured_at AS cur_at,
                    m.market_ticker, m.kalshi_market_id, m.market_title, m.yes_subtitle, m.no_subtitle, m.event_id,
                    e.event_title, e.event_start_time
               FROM market_movements mm
               JOIN market_snapshots ps ON ps.id = mm.previous_snapshot_id
               JOIN market_snapshots cs ON cs.id = mm.current_snapshot_id
               JOIN markets m ON m.id = mm.market_id
               JOIN events  e ON e.id = m.event_id
              WHERE mm.id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    private function save(int $movementId, string $source, array $r): void
    {
        $this->db->prepare(
            'INSERT INTO movement_evidence (movement_id, source, status, stance, score, occurred_at, headline, detail, url,
                                            item_count, matched_keywords, error_message, raw_data)
             VALUES (:m, :src, :st, :stance, :score, :at, :head, :detail, :url, :n, :kw, :err, :raw)
             ON CONFLICT (movement_id, source) DO UPDATE
                SET status = EXCLUDED.status, stance = EXCLUDED.stance, score = EXCLUDED.score,
                    occurred_at = EXCLUDED.occurred_at, headline = EXCLUDED.headline, detail = EXCLUDED.detail,
                    url = EXCLUDED.url, item_count = EXCLUDED.item_count, matched_keywords = EXCLUDED.matched_keywords,
                    error_message = EXCLUDED.error_message, raw_data = EXCLUDED.raw_data,
                    attempts = movement_evidence.attempts + 1, collected_at = NOW()'
        )->execute([
            ':m'      => $movementId,
            ':src'    => $source,
            ':st'     => $r['status'],
            ':stance' => $r['stance'] ?? 'neutral',
            ':score'  => max(0, (int) ($r['score'] ?? 0)),
            ':at'     => $r['occurred_at'] ?? null,
            ':head'   => isset($r['headline']) ? mb_substr($r['headline'], 0, 500) : null,
            ':detail' => isset($r['detail']) ? mb_substr($r['detail'], 0, 500) : null,
            ':url'    => (isset($r['url']) && preg_match('#^https://#i', (string) $r['url'])) ? $r['url'] : null,
            ':n'      => max(0, (int) ($r['item_count'] ?? 0)),
            ':kw'     => json_encode(array_values($r['matched_keywords'] ?? [])),
            ':err'    => $r['error'] ?? null,
            ':raw'    => isset($r['raw']) ? json_encode($r['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
        ]);
    }

    private static function last(string $name): string
    {
        $parts = preg_split('/\s+/', trim(preg_replace('/,?\s+\b(jr|sr|ii|iii)\.?$/i', '', $name)));
        return (string) end($parts);
    }
}
