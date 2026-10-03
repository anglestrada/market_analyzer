<?php
declare(strict_types=1);

/**
 * Collects evidence for a drop from ESPN (fight status, play-by-play, news) and Kalshi's own trades,
 * and saves the best result per source in movement_evidence (TheNewsAPI stays in movement_analysis).
 * None of these need an API key.
 *
 * Each source is independent: one failing never stops the others and never fails the scan.
 * Failed sources are retried on the next two scans.
 *
 * It also keeps events.espn_data current (records, weight class, odds, status, stats) for the Overview's
 * "Fight info" block — see refreshFightInfo().
 */
final class EvidenceCollector
{
    private const MAX_ATTEMPTS = 3;

    /** Human-readable summary of the last collect() call, for the scan log. */
    public string $lastSummary = '';

    /** ESPN fight found during the current collect() call (shared by the espn / espn_plays / espn_news sources). */
    private ?array $fight = null;
    private bool $fightLooked = false;

    public function __construct(
        private PDO $db,
        private KalshiClient $kalshi,
        private LiveSignals $signals,
        private ?EspnClient $espn = null,
    ) {}

    public static function make(PDO $db, KalshiClient $kalshi): self
    {
        return new self($db, $kalshi, new LiveSignals($db), ESPN_ENABLED ? new EspnClient() : null);
    }

    /** Sources that will be searched with the current .env. */
    public function enabledSources(): array
    {
        return array_values(array_filter(EVIDENCE_SOURCES, fn($s) => match ($s) {
            'espn', 'espn_plays', 'espn_news' => $this->espn !== null,
            'kalshi_trades'                   => KALSHI_TRADES_ENABLED,
            default                           => false,
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

        $cur   = new DateTimeImmutable($mv['cur_at'] ?? $mv['detected_at']);
        $prev  = new DateTimeImmutable($mv['prev_at'] ?? $mv['detected_at']);
        $from  = $prev->modify('-' . LIVE_LOOKBACK_MINUTES . ' minutes');
        $floor = $cur->modify('-6 hours');                 // scanner was offline → don't look back a whole day
        $from  = $from < $floor ? $floor : $from;

        $this->fight       = null;
        $this->fightLooked = false;
        $results = [];
        $notes   = [];
        foreach ($this->enabledSources() as $source) {
            if ($only !== null && !in_array($source, $only, true)) {
                continue;
            }
            try {
                $row = match ($source) {
                    'espn'          => $this->fromEspn($mv, $ctx, $cur),
                    'espn_plays'    => $this->fromEspnPlays($mv, $ctx, $from, $cur),
                    'espn_news'     => $this->fromEspnNews($mv, $ctx, $cur),
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

        $this->lastSummary = $notes ? implode('; ', $notes) : 'no evidence sources enabled';
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

    /**
     * Saves ESPN's view of every open bout in events.espn_data (once per scan while a fight is on,
     * otherwise hourly). Two or three scoreboard requests cover a whole card.
     */
    public function refreshFightInfo(?callable $say = null): void
    {
        if ($this->espn === null) {
            return;
        }
        $stmt = $this->db->prepare(
            "SELECT id, event_title, event_start_time, espn_data->>'state' AS espn_state, espn_checked_at
               FROM events
              WHERE sport = :sport AND status = 'open'
                AND (event_start_time IS NULL OR event_start_time BETWEEN NOW() - INTERVAL '1 day' AND NOW() + INTERVAL '10 days')"
        );
        $stmt->execute([':sport' => DEFAULT_SPORT]);

        $save = $this->db->prepare('UPDATE events SET espn_data = COALESCE(:d, espn_data), espn_checked_at = NOW() WHERE id = :id');
        $done = 0; $found = 0;
        foreach ($stmt->fetchAll() as $e) {
            $start   = $e['event_start_time'] ? strtotime($e['event_start_time']) : null;
            $soon    = $start === null || $start - time() < 3 * 3600;
            $checked = $e['espn_checked_at'] ? strtotime($e['espn_checked_at']) : 0;
            $live    = in_array($e['espn_state'], ['in'], true) || ($soon && $e['espn_state'] !== 'post');
            if (!$live && time() - $checked < 3600) {
                continue;   // nothing changes much before fight night
            }
            try {
                $ctx   = FightContext::forEvent($this->db, $e);
                $fight = $this->espn->fightDetails($ctx, EspnClient::candidateDays(
                    $start ? new DateTimeImmutable('@' . $start) : null, new DateTimeImmutable('now')));
                $save->execute([':d' => $fight ? self::json($fight) : null, ':id' => $e['id']]);
                $done++;
                $found += $fight ? 1 : 0;
            } catch (Throwable $ex) {
                $say && $say("ESPN fight info for event #{$e['id']} failed: " . $ex->getMessage());
            }
        }
        if ($done && $say) {
            $say("ESPN fight info: $found of $done bouts found.");
        }
    }

    /* ------------------------------------------------------------------ ESPN */

    /** The bout as ESPN sees it now: fresh saved copy from this scan if there is one, otherwise a new lookup. */
    private function fight(array $mv, FightContext $ctx, DateTimeImmutable $dropAt): ?array
    {
        if ($this->fightLooked) {
            return $this->fight;
        }
        $this->fightLooked = true;

        $saved = $mv['espn_data'] ? json_decode((string) $mv['espn_data'], true) : null;
        if (is_array($saved) && !empty($saved['checked_at']) && time() - strtotime($saved['checked_at']) < 240
            && time() - $dropAt->getTimestamp() < 600) {
            return $this->fight = $saved;
        }
        $start = $mv['event_start_time'] ? new DateTimeImmutable($mv['event_start_time']) : null;
        return $this->fight = $this->espn->fightDetails($ctx, EspnClient::candidateDays($dropAt, $start));
    }

    private function fromEspn(array $mv, FightContext $ctx, DateTimeImmutable $dropAt): array
    {
        $fight = $this->fight($mv, $ctx, $dropAt);
        if ($fight === null) {
            return ['status' => 'none', 'detail' => 'Fight not found on ESPN\'s scoreboard'];
        }

        $lagMin = (int) round((time() - $dropAt->getTimestamp()) / 60);
        $lag    = $lagMin > 10 ? "checked $lagMin min after the drop" : null;
        [$subj, $opp] = $this->sides($fight, $ctx);
        $expected = $ctx->expectedDirection();
        $stanceOf = fn(int $dir) => ($expected === null || $dir === 0) ? 'neutral' : ($dir === $expected ? 'supports' : 'contradicts');
        $base = ['occurred_at' => gmdate(DATE_ATOM), 'url' => $fight['url'] ?? EspnClient::fightUrl($fight), 'raw' => $fight];
        $join = fn(array $parts) => implode(' · ', array_filter($parts));

        if ($fight['state'] === 'pre') {
            return $base + [
                'status'   => 'found',
                'stance'   => 'neutral',
                'score'    => 1,
                'headline' => 'Fight hadn\'t started (pre-fight drop)',
                'detail'   => $join([self::cardLine($fight), self::recordsLine($subj, $opp), self::oddsLine($fight, $subj, $opp), $lag]),
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
                    'headline' => 'Fight over: ' . ($fight['detail'] ?: 'result not posted yet'), 'detail' => $join([$lag])];
            }
            $loser  = $winner === $subj ? $opp : ($winner === $opp ? $subj : null);
            $dir    = $subj === null ? 0 : ($winner === $subj ? 1 : -1);
            $how    = trim(implode(' ', array_filter([$fight['result'], $fight['round'] ? 'R' . $fight['round'] : null, $fight['clock']])));
            $stance = $stanceOf($dir);
            return $base + [
                'status'   => 'found',
                'stance'   => $stance,
                'score'    => $stance === 'supports' ? 25 : 2,   // an official result outranks everything else
                'headline' => sprintf('Final: %s def. %s', $winner['name'], $loser['name'] ?? 'opponent'),
                'detail'   => $join([$how ?: $fight['detail'], self::statsLine($fight, $subj, $opp), self::roundsLine($fight, $subj, $opp), $lag]),
            ];
        }

        // In progress (or between rounds): who is ahead on the stats?
        $dir = 0;
        $s   = $fight['stats'] ?? [];
        if ($subj && $opp && isset($s[$subj['id']]['sig'], $s[$opp['id']]['sig'])) {
            $a    = $s[$subj['id']];
            $b    = $s[$opp['id']];
            $edge = ($a['sig'] - $b['sig']) + 10 * (($a['kd'] ?? 0) - ($b['kd'] ?? 0)) + 3 * (($a['td'] ?? 0) - ($b['td'] ?? 0));
            $dir  = abs($edge) >= 8 ? ($edge <=> 0) : 0;
        }
        $stance = $stanceOf($dir);
        return $base + [
            'status'   => 'found',
            'stance'   => $stance,
            'score'    => 5 + ($stance === 'supports' ? 3 : 0),
            'headline' => 'In progress: ' . ($fight['detail'] ?: trim('Round ' . ($fight['round'] ?? '?') . ' ' . ($fight['clock'] ?? ''))),
            'detail'   => $join([self::statsLine($fight, $subj, $opp), self::roundsLine($fight, $subj, $opp), $lag]) ?: 'Live fight',
        ];
    }

    /** Key moments (knockdowns, takedowns, submission attempts, point deductions) between the scans. */
    private function fromEspnPlays(array $mv, FightContext $ctx, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $fight = $this->fight($mv, $ctx, $to);
        if ($fight === null) {
            return ['status' => 'none', 'detail' => 'Fight not found on ESPN\'s scoreboard'];
        }
        if ($fight['state'] === 'pre') {
            return ['status' => 'none', 'detail' => 'Fight hadn\'t started, so there is no play-by-play'];
        }
        $plays = $this->espn->plays($fight);
        if (!$plays) {
            return ['status' => 'none', 'detail' => 'ESPN has no play-by-play for this fight'];
        }

        // Plays in the window by wall-clock time; without timestamps, fall back to the current (or last) round.
        $timed  = array_filter($plays, fn($p) => $p['at'] !== null);
        $note   = null;
        if ($timed) {
            $lo = $from->getTimestamp(); $hi = $to->getTimestamp() + 60;
            $window = array_values(array_filter($timed, fn($p) => ($t = strtotime($p['at'])) >= $lo && $t <= $hi));
        } else {
            $round  = $fight['round'] ?? max(array_map(fn($p) => (int) $p['round'], $plays));
            $window = array_values(array_filter($plays, fn($p) => (int) $p['round'] === (int) $round));
            $note   = "ESPN gave no play times; showing round $round";
        }
        if (!$window) {
            return ['status' => 'none', 'detail' => 'No plays between the two scans (' . count($plays) . ' in the fight)'];
        }

        $byAthlete = [];
        foreach ($fight['fighters'] as $f) {
            $byAthlete[$f['athlete_id']] = $f['name'];
            $byAthlete[$f['id']]         = $f['name'];
        }
        $net = 0; $top = null; $count = []; $kinds = [];
        foreach ($window as $p) {
            $what = strtolower($p['type'] . ' ' . $p['text']);
            [$kind, $weight, $sign] = match (true) {
                str_contains($what, 'knockdown') || str_contains($what, 'knocked down') => ['knockdown', 8, 1],
                (bool) preg_match('/\b(ko|tko)\b|knockout/', $what)                      => ['KO/TKO', 12, 1],
                str_contains($what, 'point') && str_contains($what, 'deduct')          => ['point deduction', 6, -1],
                str_contains($what, 'submission') && !str_contains($what, 'defen')     => ['submission attempt', 5, 1],
                str_contains($what, 'takedown') && !preg_match('/defen|stuff|block|miss/', $what) => ['takedown', 3, 1],
                str_contains($what, 'significant') || str_contains($what, 'sig.')     => ['sig. strike', 1, 1],
                default                                                                => [null, 0, 0],
            };
            if ($kind === null) {
                continue;
            }
            $name    = $byAthlete[(string) $p['athlete_id']] ?? null;
            $fighter = $name ? $ctx->whichFighter($name) : $ctx->fighterAt($ctx->mentions($p['text']), 0);
            if ($fighter === null) {
                continue;
            }
            $forSubject = ($ctx->subject !== null && $fighter !== $ctx->subject) ? -1 : 1;
            $net += $forSubject * $sign * $weight;
            $count[$fighter][$kind] = ($count[$fighter][$kind] ?? 0) + 1;
            $kinds[$kind] = true;
            if ($weight > 1 && ($top === null || $weight > $top['weight'])) {
                $top = $p + ['weight' => $weight, 'kind' => $kind, 'fighter' => $fighter];
            }
        }
        if (!$kinds) {
            return ['status' => 'none', 'detail' => count($window) . ' plays between the scans, none of them key moments'];
        }

        $expected = $ctx->expectedDirection();
        $dir      = abs($net) >= 3 ? ($net <=> 0) : 0;
        $stance   = ($expected === null || $dir === 0) ? 'neutral' : ($dir === $expected ? 'supports' : 'contradicts');

        $summary = [];
        foreach ($count as $fighter => $k) {
            $summary[] = self::lastName($fighter) . ': ' . implode(', ', array_map(fn($kind, $n) => "$n $kind" . ($n > 1 && !str_ends_with($kind, 's') ? 's' : ''), array_keys($k), $k));
        }
        $when = $top ? trim(($top['round'] ? 'R' . $top['round'] : '') . ' ' . ($top['clock'] ?? '')) : '';
        $headline = $top
            ? ($top['text'] !== '' ? $top['text'] : self::lastName($top['fighter']) . ' ' . $top['kind']) . ($when !== '' ? " ($when)" : '')
            : 'Strike exchange: ' . implode(' · ', $summary);

        return [
            'status'      => 'found',
            'stance'      => $stance,
            'score'       => min(15, abs($net)),
            'headline'    => Http::snippet($headline, 200),
            'detail'      => implode(' · ', array_filter([implode(' · ', $summary), $note])),
            'url'         => $fight['url'] ?? EspnClient::fightUrl($fight),
            'occurred_at' => $top['at'] ?? end($window)['at'] ?? $to->format(DATE_ATOM),
            'item_count'  => count($window),
            'raw'         => ['plays' => array_slice($window, 0, 40), 'net' => $net],
        ];
    }

    /** ESPN MMA news + each fighter's feed, NEWS_LOOKBACK_HOURS before the drop, scored with the keyword rules. */
    private function fromEspnNews(array $mv, FightContext $ctx, DateTimeImmutable $dropAt): array
    {
        $fight = $this->fight($mv, $ctx, $dropAt);
        $ids   = $fight ? array_column($fight['fighters'], 'athlete_id') : [];
        $lo    = $dropAt->getTimestamp() - NEWS_LOOKBACK_HOURS * 3600;
        $hi    = $dropAt->getTimestamp() + 300;

        $related = 0; $against = 0; $best = null;
        $rank = ['supports' => 2, 'neutral' => 1, 'contradicts' => 0];
        foreach ($this->espn->news($ids) as $a) {
            $t = $a['at'] ? strtotime($a['at']) : null;
            if ($t === null || $t < $lo || $t > $hi || !$ctx->mentionsAny($a['text'])) {
                continue;
            }
            $related++;
            $s = $this->signals->analyze($a['text'], $ctx);
            if ($s === null) {
                continue;
            }
            if ($s['stance'] === 'contradicts') {
                $against++;
                continue;
            }
            $key = [$rank[$s['stance']], $s['relevance'] + abs($s['net']), $t];
            if ($best === null || $key > $best['key']) {
                $best = ['key' => $key, 'article' => $a, 'signal' => $s];
            }
        }

        if ($best === null) {
            return ['status' => 'none', 'item_count' => $related, 'detail' => $related
                ? "$related ESPN article" . ($related === 1 ? '' : 's') . ' about the fighters' . ($against ? ", $against pointing the other way" : ', none matched the keyword rules')
                : 'No ESPN articles about either fighter in the last ' . NEWS_LOOKBACK_HOURS . ' hours'];
        }
        $a = $best['article'];
        $s = $best['signal'];
        $others = $related - 1;
        return [
            'status'           => 'found',
            'stance'           => $s['stance'],
            'score'            => min(20, $s['relevance']),
            'headline'         => $a['title'],
            'detail'           => implode(' · ', array_filter([$a['author'], $others > 0 ? "$others more about the fighters" : null,
                                      $against ? "$against point the other way" : null])),
            'url'              => $a['url'],
            'occurred_at'      => $a['at'],
            'item_count'       => $related,
            'matched_keywords' => $s['matched'],
            'raw'              => ['article' => $a, 'signal' => $s],
        ];
    }

    /** @return array{0:?array,1:?array} [subject's ESPN competitor, opponent's] */
    private function sides(array $fight, FightContext $ctx): array
    {
        $subj = null; $opp = null;
        foreach ($fight['fighters'] as $f) {
            if ($ctx->subject !== null && $ctx->whichFighter($f['name']) === $ctx->subject) {
                $subj = $f;
            } else {
                $opp = $f;
            }
        }
        return [$subj, $opp];
    }

    private static function cardLine(array $fight): ?string
    {
        $parts = array_filter([$fight['weight_class'] ?? null, !empty($fight['rounds']) ? $fight['rounds'] . ' rounds' : null]);
        return $parts ? implode(', ', $parts) : null;
    }

    private static function recordsLine(?array $a, ?array $b): ?string
    {
        if (!$a || !$b || (!$a['record'] && !$b['record'])) {
            return null;
        }
        return sprintf('%s %s vs %s %s', self::lastName($a['name']), $a['record'] ?? '?', self::lastName($b['name']), $b['record'] ?? '?');
    }

    private static function oddsLine(array $fight, ?array $a, ?array $b): ?string
    {
        $o = $fight['odds'] ?? null;
        if (!$o || !$a || !$b || !isset($o['lines'][$a['id']], $o['lines'][$b['id']])) {
            return null;
        }
        $fmt = fn(array $f) => sprintf('%s %+d (%d%%)', self::lastName($f['name']), $o['lines'][$f['id']],
            round(EspnClient::impliedProbability($o['lines'][$f['id']]) * 100));
        return $o['provider'] . ': ' . $fmt($a) . ', ' . $fmt($b);
    }

    private static function statsLine(array $fight, ?array $a, ?array $b): ?string
    {
        $s = $fight['stats'] ?? [];
        if (!$a || !$b || !isset($s[$a['id']], $s[$b['id']])) {
            return null;
        }
        $x = $s[$a['id']]; $y = $s[$b['id']];
        $parts = [];
        foreach (['sig' => 'Sig. strikes', 'kd' => 'Knockdowns', 'td' => 'Takedowns', 'sub' => 'Sub attempts', 'ctrl' => 'Control'] as $k => $label) {
            if (isset($x[$k]) || isset($y[$k])) {
                if ($k !== 'sig' && $k !== 'ctrl' && (int) ($x[$k] ?? 0) + (int) ($y[$k] ?? 0) === 0) {
                    continue;
                }
                $parts[] = sprintf('%s %s–%s', $label, $x[$k] ?? 0, $y[$k] ?? 0);
            }
        }
        return $parts ? self::lastName($a['name']) . ' vs ' . self::lastName($b['name']) . ': ' . implode(', ', $parts) : null;
    }

    private static function roundsLine(array $fight, ?array $a, ?array $b): ?string
    {
        $l = $fight['linescores'] ?? [];
        if (!$a || !$b || empty($l[$a['id']]) || empty($l[$b['id']])) {
            return null;
        }
        $parts = [];
        foreach ($l[$a['id']] as $round => $score) {
            if (isset($l[$b['id']][$round])) {
                $parts[] = sprintf('R%d %s–%s', $round, $score + 0, $l[$b['id']][$round] + 0);
            }
        }
        return $parts ? 'Judges: ' . implode(', ', $parts) : null;
    }

    /* ------------------------------------------------------------------ Kalshi trades */

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
        $total = 0; $pushing = 0; $fills = 0; $bursts = []; $first = null; $last = null;
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

        $share  = $total > 0 ? $pushing / $total : 0.0;
        $stance = $share >= 0.6 ? 'supports' : ($share <= 0.4 ? 'contradicts' : 'neutral');

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
            'headline'    => sprintf('%s %s contracts bought in %s', number_format($pushing), strtoupper($against),
                $fills === 1 ? 'one trade' : ($minutes === 1 ? 'about a minute' : "$minutes min")),
            'detail'      => implode(' · ', $detail),
            'occurred_at' => gmdate(DATE_ATOM, $bigTs),
            'item_count'  => $fills,
            'raw'         => ['total' => $total, 'pushing' => $pushing, 'share' => round($share, 3), 'largest_burst' => $big,
                              'first' => gmdate(DATE_ATOM, $first), 'last' => gmdate(DATE_ATOM, $last), 'trades' => count($trades)],
        ];
    }

    /* ------------------------------------------------------------------ storage */

    private function movement(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT mm.id, mm.market_id, mm.dropped_side, mm.detected_at,
                    ps.captured_at AS prev_at, cs.captured_at AS cur_at,
                    m.market_ticker, m.kalshi_market_id, m.market_title, m.yes_subtitle, m.no_subtitle, m.event_id,
                    e.event_title, e.event_start_time, e.espn_data
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
            ':raw'    => isset($r['raw']) ? self::json($r['raw']) : null,
        ]);
    }

    private static function json(array $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function lastName(string $name): string
    {
        $parts = preg_split('/\s+/', trim(preg_replace('/,?\s+\b(jr|sr|ii|iii)\.?$/i', '', $name)));
        return (string) end($parts);
    }
}
