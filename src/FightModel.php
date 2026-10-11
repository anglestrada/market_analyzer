<?php
declare(strict_types=1);

/**
 * Fight prediction model v1 (see README → Fight prediction). Reads only the database.
 *
 *   Δ = 0.35·Δz(Resume) + 0.35·Δz(Performance) + 0.20·Matchup + 0.10·ΔContext
 *   P(fighter wins) = 1 / (1 + e^(−1.6·Δ))
 *
 * z-scores of a difference only need a spread (the average cancels out), so each stat has a
 * reference standard deviation below. They're sensible starting values for UFC fighters;
 * refit them (and the weights) once enough past fights are stored.
 */
final class FightModel
{
    public const VERSION = 'v1';

    private const W_RESUME = 0.35, W_PERF = 0.35, W_MATCHUP = 0.20, W_CONTEXT = 0.10;
    private const SCALE = 1.6;
    private const PERF_FIGHTS = 7;

    /** Reference spreads (standard deviations) for the z-scores. */
    private const SD = [
        'resume' => 1.5,
        'sig_diff' => 1.6, 'str_def' => 0.07, 'kd15' => 0.40, 'td15' => 1.40, 'td_def' => 0.20,
        'ctrl' => 0.12, 'sub15' => 0.60, 'kd_abs15' => 0.40,
        'edge_strike' => 1.20, 'edge_grapple' => 0.80,
    ];

    /** Labels for the breakdown on the prediction page. */
    public const STAT_LABELS = [
        'sig_diff' => 'Sig. strike differential / min', 'str_def' => 'Striking defense', 'kd15' => 'Knockdowns / 15 min',
        'td15' => 'Takedowns / 15 min', 'td_def' => 'Takedown defense', 'ctrl' => 'Control time %',
        'sub15' => 'Submission attempts / 15 min', 'kd_abs15' => 'Knocked down / 15 min',
    ];

    public function __construct(private PDO $db) {}

    /**
     * Prediction for one Kalshi market ("Will <fighter> win?"), or null with a reason.
     * @return array{ok:bool, reason?:string, p?:float, delta?:float, parts?:array, a?:array, b?:array, notes?:array}
     */
    public function predictMarket(int $marketId, ?DateTimeImmutable $asOf = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT mf.*, uf.weight_class AS upcoming_weight_class, uf.fight_date AS upcoming_date
               FROM market_fighters mf LEFT JOIN fights uf ON uf.id = mf.upcoming_fight_id WHERE mf.market_id = :m'
        );
        $stmt->execute([':m' => $marketId]);
        $link = $stmt->fetch();
        if (!$link) {
            return ['ok' => false, 'reason' => 'not_linked'];
        }
        if (!$link['opponent_id']) {
            return ['ok' => false, 'reason' => 'no_opponent'];
        }
        $asOf ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $class = $link['upcoming_weight_class'] ?: null;

        $a = $this->fighterProfile((int) $link['fighter_id'], $asOf, $class);
        $b = $this->fighterProfile((int) $link['opponent_id'], $asOf, $class);
        if ($a === null || $b === null) {
            return ['ok' => false, 'reason' => 'not_synced'];
        }
        if ($a['fights'] === 0 || $b['fights'] === 0) {
            return ['ok' => false, 'reason' => 'no_history', 'a' => $a, 'b' => $b];
        }

        // 1. Resume
        $resume = ($a['resume'] - $b['resume']) / self::SD['resume'];

        // 2. Performance: average z-difference over the stats both fighters have
        $perfParts = [];
        foreach (array_keys(self::STAT_LABELS) as $k) {
            $x = $a['perf'][$k] ?? null;
            $y = $b['perf'][$k] ?? null;
            if ($x === null || $y === null) {
                continue;
            }
            $z = ($x - $y) / self::SD[$k];
            $perfParts[$k] = $k === 'kd_abs15' ? -$z : $z;   // being knocked down is bad
        }
        $perf = $perfParts ? array_sum($perfParts) / count($perfParts) : 0.0;

        // 3. Style matchup: A's offense against B's defense, minus the reverse
        $matchup = 0.0;
        $edges   = [];
        if (isset($a['perf']['sig_att_pm'], $b['perf']['sig_att_pm'], $a['perf']['str_def'], $b['perf']['str_def'])) {
            $edges['striking'] = $a['perf']['sig_att_pm'] * (1 - $b['perf']['str_def'])
                               - $b['perf']['sig_att_pm'] * (1 - $a['perf']['str_def']);
            $matchup += $edges['striking'] / self::SD['edge_strike'];
        }
        if (isset($a['perf']['td_att15'], $b['perf']['td_att15'])) {
            $edges['grappling'] = $a['perf']['td_att15'] * (1 - ($b['perf']['td_def'] ?? 0.65))
                                - $b['perf']['td_att15'] * (1 - ($a['perf']['td_def'] ?? 0.65));
            $matchup += $edges['grappling'] / self::SD['edge_grapple'];
        }
        $matchup = max(-3.0, min(3.0, $matchup));

        // 4. Context
        $context = $a['context']['total'] - $b['context']['total'];

        $delta = self::W_RESUME * $resume + self::W_PERF * $perf + self::W_MATCHUP * $matchup + self::W_CONTEXT * $context;
        $p     = 1 / (1 + exp(-self::SCALE * $delta));
        $p     = max(0.03, min(0.97, $p));

        $notes = [];
        foreach (['a' => $a, 'b' => $b] as $side => $f) {
            if (!$f['complete']) {
                $notes[] = "{$f['name']}'s ESPN history is still syncing, so this number will change.";
            }
            if ($f['perf_fights'] === 0) {
                $notes[] = "{$f['name']} has no fights with ESPN stats, so performance and matchup are left out for that side.";
            } elseif ($f['perf_fights'] < 3) {
                $notes[] = "{$f['name']} has only {$f['perf_fights']} fight(s) with ESPN stats.";
            }
            if ($f['approx_records'] > 0) {
                $notes[] = "{$f['approx_records']} of {$f['name']}'s opponents use their current record (record on the day not known yet).";
            }
        }

        return [
            'ok'    => true,
            'complete' => $a['complete'] && $b['complete'],
            'p'     => round($p, 4),
            'delta' => round($delta, 4),
            'parts' => [
                'resume'      => ['value' => round($resume, 3),  'weight' => self::W_RESUME],
                'performance' => ['value' => round($perf, 3),    'weight' => self::W_PERF, 'stats' => array_map(fn($v) => round($v, 3), $perfParts)],
                'matchup'     => ['value' => round($matchup, 3), 'weight' => self::W_MATCHUP, 'edges' => array_map(fn($v) => round($v, 3), $edges)],
                'context'     => ['value' => round($context, 3), 'weight' => self::W_CONTEXT],
            ],
            'a'     => $a,
            'b'     => $b,
            'notes' => $notes,
        ];
    }

    /**
     * Everything the model knows about one fighter on $asOf.
     * @return array|null null when the fighter's profile hasn't been synced yet
     */
    public function fighterProfile(int $fighterId, DateTimeImmutable $asOf, ?string $upcomingClass = null): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM fighters WHERE id = :id');
        $stmt->execute([':id' => $fighterId]);
        $f = $stmt->fetch();
        if (!$f || $f['profile_synced_at'] === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT fi.id AS fight_id, fi.fight_date, fi.league, fi.is_title, fi.title_type, fi.method, fi.weight_class,
                    fi.duration_seconds, fi.end_round, fp.result,
                    o.id AS opp_id, o.full_name AS opp_name, o.wins AS opp_cur_w, o.losses AS opp_cur_l,
                    op.wins_before AS opp_w, op.losses_before AS opp_l
               FROM fight_participants fp
               JOIN fights fi ON fi.id = fp.fight_id
          LEFT JOIN fighters o ON o.id = fp.opponent_id
          LEFT JOIN fight_participants op ON op.fight_id = fp.fight_id AND op.fighter_id = fp.opponent_id
              WHERE fp.fighter_id = :id AND fi.status_state = 'post' AND fp.result IS NOT NULL AND fi.fight_date < :asof
              ORDER BY fi.fight_date DESC"
        );
        $stmt->execute([':id' => $fighterId, ':asof' => $asOf->format('c')]);
        $fights = $stmt->fetchAll();

        // ---- 1. Resume
        $resume = 0.0;
        $approx = 0;
        $resumeRows = [];
        foreach ($fights as $r) {
            if ($r['opp_w'] !== null) {
                $w = (int) $r['opp_w']; $l = (int) $r['opp_l'];
            } else {
                $approx++;
                $w = (int) $r['opp_cur_w']; $l = (int) $r['opp_cur_l'];
            }
            $q   = ($w + 1) / ($w + $l + 2);
            $e   = min(1.0, ($w + $l) / 10);
            $rec = 0.5 ** (self::years($r['fight_date'], $asOf) / 3);
            $lg  = ($r['league'] ?? 'ufc') === 'ufc' ? 1.0 : 0.6;
            $m   = self::methodMultiplier((string) $r['method']);
            $t   = !self::truthy($r['is_title']) ? 1.0 : ($r['title_type'] === 'interim' ? 1.5 : 2.0);
            $pts = match ($r['result']) {
                'W'     => $q * $e * $m * $t * $rec * $lg,
                'L'     => -(1 - $q) * $rec * $lg,
                default => 0.0,
            };
            $resume += $pts;
            $resumeRows[] = [
                'date' => $r['fight_date'], 'opponent' => $r['opp_name'], 'result' => $r['result'], 'method' => $r['method'],
                'opp_record' => "$w-$l", 'opp_record_exact' => $r['opp_w'] !== null, 'title' => self::truthy($r['is_title']),
                'league' => $r['league'], 'points' => round($pts, 3),
            ];
        }

        // ---- 2. Performance (UFC fights with stats for both fighters, most recent first)
        $stmt = $this->db->prepare(
            "SELECT fi.fight_date, fi.duration_seconds, fp.opponent_id,
                    me.sig_strikes_landed AS sl, me.sig_strikes_attempted AS sa, me.knockdowns AS kd,
                    me.takedowns_landed AS tdl, me.takedowns_attempted AS tda, me.control_seconds AS ctrl,
                    me.submission_attempts AS sub, me.sig_distance_landed AS dist, me.sig_clinch_landed AS clinch,
                    me.sig_ground_landed AS ground,
                    op.sig_strikes_landed AS o_sl, op.sig_strikes_attempted AS o_sa, op.knockdowns AS o_kd,
                    op.takedowns_landed AS o_tdl, op.takedowns_attempted AS o_tda,
                    ow.wins_before AS opp_w, ow.losses_before AS opp_l, o.wins AS opp_cur_w, o.losses AS opp_cur_l
               FROM fight_participants fp
               JOIN fights fi ON fi.id = fp.fight_id
               JOIN fight_stats me ON me.fight_id = fp.fight_id AND me.fighter_id = fp.fighter_id
               JOIN fight_stats op ON op.fight_id = fp.fight_id AND op.fighter_id = fp.opponent_id
          LEFT JOIN fight_participants ow ON ow.fight_id = fp.fight_id AND ow.fighter_id = fp.opponent_id
          LEFT JOIN fighters o ON o.id = fp.opponent_id
              WHERE fp.fighter_id = :id AND fi.status_state = 'post' AND fi.duration_seconds >= 60 AND fi.fight_date < :asof
              ORDER BY fi.fight_date DESC
              LIMIT " . self::PERF_FIGHTS
        );
        $stmt->execute([':id' => $fighterId, ':asof' => $asOf->format('c')]);
        $statRows = $stmt->fetchAll();

        $acc = [];
        $add = function (string $k, ?float $v, float $w) use (&$acc): void {
            if ($v === null || !is_finite($v)) {
                return;
            }
            $acc[$k][0] = ($acc[$k][0] ?? 0) + $v * $w;
            $acc[$k][1] = ($acc[$k][1] ?? 0) + $w;
        };
        $shares = ['distance' => 0, 'clinch' => 0, 'ground' => 0];
        foreach ($statRows as $s) {
            $min = $s['duration_seconds'] / 60;
            $w0  = $s['opp_w'] !== null ? (int) $s['opp_w'] : (int) $s['opp_cur_w'];
            $l0  = $s['opp_l'] !== null ? (int) $s['opp_l'] : (int) $s['opp_cur_l'];
            $q   = ($w0 + 1) / ($w0 + $l0 + 2);
            $wt  = (0.5 ** (self::years($s['fight_date'], $asOf) / 3)) * max(0.3, $q);

            $add('sig_diff', ($s['sl'] - $s['o_sl']) / $min, $wt);
            $add('sig_att_pm', $s['sa'] / $min, $wt);
            $add('str_def', $s['o_sa'] > 0 ? 1 - $s['o_sl'] / $s['o_sa'] : null, $wt);
            $add('kd15', $s['kd'] * 15 / $min, $wt);
            $add('td15', $s['tdl'] * 15 / $min, $wt);
            $add('td_att15', $s['tda'] * 15 / $min, $wt);
            $add('td_def', $s['o_tda'] > 0 ? 1 - $s['o_tdl'] / $s['o_tda'] : null, $wt);
            $add('ctrl', $s['ctrl'] / $s['duration_seconds'], $wt);
            $add('sub15', $s['sub'] * 15 / $min, $wt);
            $add('kd_abs15', $s['o_kd'] * 15 / $min, $wt);
            $shares['distance'] += (int) $s['dist'];
            $shares['clinch']   += (int) $s['clinch'];
            $shares['ground']   += (int) $s['ground'];
        }
        $perf = array_map(fn($p) => $p[1] > 0 ? $p[0] / $p[1] : null, $acc);
        $landed = array_sum($shares);
        $shares = $landed > 0 ? array_map(fn($v) => round($v / $landed, 3), $shares) : null;

        // ---- 3. Style label
        $style = null;
        if ($perf) {
            $style = match (true) {
                ($perf['td15'] ?? 0) >= 2.5 || ($perf['ctrl'] ?? 0) >= 0.35 => 'Wrestler',
                ($perf['sub15'] ?? 0) >= 1.0                                  => 'Grappler',
                ($shares['ground'] ?? 0) >= 0.30                              => 'Ground and pound',
                ($perf['kd15'] ?? 0) >= 0.6                                   => 'Power striker',
                default                                                       => 'Striker',
            };
        }

        // ---- 4. Context
        $age     = $f['date_of_birth'] ? self::years($f['date_of_birth'], $asOf) : null;
        $last    = $fights[0] ?? null;
        $layoff  = $last ? self::years($last['fight_date'], $asOf) * 12 : null;   // months
        $newDiv  = $upcomingClass && $last && $last['weight_class']
            && !preg_match('/' . preg_quote(self::division((string) $upcomingClass), '/') . '/i', (string) $last['weight_class']);
        $ctx = [
            'age'      => $age !== null ? -0.15 * max(0, $age - 33) : 0.0,
            'reach'    => $f['reach_in'] !== null ? 0.03 * ((float) $f['reach_in'] - 70) : 0.0,  // relative; the 70 cancels out
            'layoff'   => $layoff !== null && $layoff > 18 ? -0.2 : 0.0,
            'division' => $newDiv ? -0.2 : 0.0,
        ];
        $ctx['total'] = array_sum($ctx);

        $wins = count(array_filter($fights, fn($r) => $r['result'] === 'W'));
        return [
            'id' => $fighterId, 'name' => $f['full_name'], 'record' => "{$f['wins']}-{$f['losses']}-{$f['draws']}",
            'age' => $age !== null ? (int) floor($age) : null, 'height_in' => $f['height_in'], 'reach_in' => $f['reach_in'],
            'stance' => $f['stance'], 'weight_class' => $f['weight_class'], 'association' => $f['association'],
            'fights' => count($fights), 'wins_stored' => $wins, 'fights_listed' => $f['fights_listed'],
            'resume' => round($resume, 4), 'resume_rows' => $resumeRows, 'approx_records' => $approx,
            'perf' => $perf, 'perf_fights' => count($statRows), 'shares' => $shares, 'style' => $style,
            'context' => array_map(fn($v) => round($v, 3), $ctx),
            'layoff_months' => $layoff !== null ? (int) round($layoff) : null,
            'complete' => $f['history_synced_at'] !== null,
        ];
    }

    /**
     * Saves a prediction for every linked open market whose probability changed (≥ 0.5 pp) or is older than 6 h,
     * so the chart gets a point whenever the model moves without filling the table every scan.
     */
    public function predictAll(?int $syncRunId = null, ?callable $say = null): int
    {
        $rows = $this->db->query(
            "SELECT mf.market_id, mf.fighter_id, mf.opponent_id,
                    (SELECT yes_price FROM market_snapshots s WHERE s.market_id = mf.market_id ORDER BY captured_at DESC LIMIT 1) AS yes_price
               FROM market_fighters mf JOIN markets m ON m.id = mf.market_id
              WHERE m.status = 'open' AND mf.opponent_id IS NOT NULL"
        )->fetchAll();

        $last = $this->db->prepare(
            'SELECT win_probability, created_at FROM predictions WHERE market_id = :m AND model_version = :v ORDER BY created_at DESC LIMIT 1'
        );
        $ins = $this->db->prepare(
            'INSERT INTO predictions (market_id, fighter_id, opponent_id, sync_run_id, model_version, win_probability, kalshi_yes_price,
                                      resume_score, performance_score, matchup_score, context_score, delta, components)
             VALUES (:m, :f, :o, :run, :v, :p, :k, :rs, :ps, :ms, :cs, :d, :c)'
        );
        $saved = 0;
        foreach ($rows as $r) {
            $res = $this->predictMarket((int) $r['market_id']);
            if (!$res['ok'] || !$res['complete']) {
                continue;   // don't chart half-synced numbers
            }
            $last->execute([':m' => $r['market_id'], ':v' => self::VERSION]);
            $prev = $last->fetch();
            if ($prev && abs((float) $prev['win_probability'] - $res['p']) < 0.005 && strtotime($prev['created_at']) > time() - 6 * 3600) {
                continue;
            }
            $ins->execute([
                ':m' => $r['market_id'], ':f' => $r['fighter_id'], ':o' => $r['opponent_id'], ':run' => $syncRunId,
                ':v' => self::VERSION, ':p' => $res['p'], ':k' => $r['yes_price'],
                ':rs' => $res['parts']['resume']['value'], ':ps' => $res['parts']['performance']['value'],
                ':ms' => $res['parts']['matchup']['value'], ':cs' => $res['parts']['context']['value'], ':d' => $res['delta'],
                ':c' => json_encode([
                    'parts' => $res['parts'], 'notes' => $res['notes'],
                    'style' => [$res['a']['style'], $res['b']['style']],
                    'resume' => [$res['a']['resume'], $res['b']['resume']],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $saved++;
        }
        if ($say && $saved) {
            $say("Fight predictions: $saved updated.");
        }
        return $saved;
    }

    /* ------------------------------------------------------------------ helpers */

    public static function methodMultiplier(string $method): float
    {
        $m = strtolower($method);
        return match (true) {
            str_contains($m, 'ko') || str_contains($m, 'submission') => 1.25,
            str_contains($m, 'split') || str_contains($m, 'majority') => 0.9,
            default => 1.0,
        };
    }

    private static function years(?string $from, DateTimeImmutable $to): float
    {
        if (!$from) {
            return 0.0;
        }
        return max(0.0, ($to->getTimestamp() - strtotime($from)) / (365.25 * 86400));
    }

    /** "UFC Flyweight Title" → "Flyweight" */
    private static function division(string $typeText): string
    {
        return trim(preg_replace('/\b(ufc|interim|title|championship|bout)\b/i', '', $typeText)) ?: $typeText;
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }
}
