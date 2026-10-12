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
    public const DESCRIPTION = 'Resume + performance + style matchup + context, hand-set starting weights.';

    /**
     * Every number the algorithm uses. Saved with each prediction (model_versions.params), so a logged
     * percentage can always be traced back to the exact formula. Change any of these → bump VERSION.
     */
    public const PARAMS = [
        'weights'      => ['resume' => 0.35, 'performance' => 0.35, 'matchup' => 0.20, 'context' => 0.10],
        'scale'        => 1.6,                       // P = 1 / (1 + e^(−scale·Δ))
        'clamp'        => [0.03, 0.97],
        'resume'       => [
            'experience_fights' => 10,               // e = min(1, (W+L) / this)
            'recency_half_life_years' => 3,          // R = 0.5^(years / this)
            'league_weight_other' => 0.6,            // non-UFC fights
            'method' => ['finish' => 1.25, 'split_or_majority' => 0.9, 'decision' => 1.0],
            'title'  => ['undisputed' => 2.0, 'interim' => 1.5],
        ],
        'performance'  => ['fights' => 7, 'min_seconds' => 60, 'min_opponent_quality_weight' => 0.3],
        'matchup'      => ['clamp' => 3.0, 'default_td_defense' => 0.65],
        'context'      => [
            'age_start' => 33, 'age_per_year' => -0.15, 'reach_per_inch' => 0.03,
            'layoff_months' => 18, 'layoff' => -0.2, 'new_division' => -0.2,
        ],
    ];

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

    public static function paramsHash(): string
    {
        return substr(hash('sha256', json_encode(['params' => self::PARAMS, 'sd' => self::SD])), 0, 16);
    }

    /**
     * Records this version's exact algorithm in model_versions (once). If VERSION was reused for different
     * numbers, that's flagged in the log, because its old results would no longer match the formula.
     */
    public function registerVersion(?callable $say = null): void
    {
        $q = $this->db->prepare('SELECT params_hash FROM model_versions WHERE version = :v');
        $q->execute([':v' => self::VERSION]);
        $hash = $q->fetchColumn();
        if ($hash === false) {
            $this->db->prepare('INSERT INTO model_versions (version, params, params_hash, description) VALUES (:v, :p, :h, :d)')
                ->execute([':v' => self::VERSION, ':h' => self::paramsHash(), ':d' => self::DESCRIPTION,
                           ':p' => json_encode(['params' => self::PARAMS, 'sd' => self::SD], JSON_UNESCAPED_SLASHES)]);
        } elseif ($hash !== self::paramsHash() && $say) {
            $say('WARNING: FightModel::PARAMS changed but VERSION is still ' . self::VERSION . ' — bump VERSION so results stay comparable.');
        }
    }

    /** Compact copy of what went into a prediction, for prediction logs. */
    public static function inputs(array $res): array
    {
        $side = fn(array $f) => [
            'name' => $f['name'], 'record' => $f['record'], 'age' => $f['age'], 'reach_in' => $f['reach_in'],
            'fights' => $f['fights'], 'ufc_fights' => $f['ufc_fights'], 'other_fights' => $f['other_fights'],
            'perf_fights' => $f['perf_fights'], 'resume' => $f['resume'], 'approx_records' => $f['approx_records'],
            'style' => $f['style'], 'layoff_months' => $f['layoff_months'], 'complete' => $f['complete'],
            'perf' => array_map(fn($v) => $v === null ? null : round($v, 4), $f['perf']), 'context' => $f['context'],
        ];
        return ['version' => self::VERSION, 'params_hash' => self::paramsHash(), 'a' => $side($res['a']), 'b' => $side($res['b']),
                'parts' => $res['parts'], 'notes' => $res['notes']];
    }

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
            $tdDef = self::PARAMS['matchup']['default_td_defense'];
            $edges['grappling'] = $a['perf']['td_att15'] * (1 - ($b['perf']['td_def'] ?? $tdDef))
                                - $b['perf']['td_att15'] * (1 - ($a['perf']['td_def'] ?? $tdDef));
            $matchup += $edges['grappling'] / self::SD['edge_grapple'];
        }
        $matchup = max(-self::PARAMS['matchup']['clamp'], min(self::PARAMS['matchup']['clamp'], $matchup));

        // 4. Context (reach counts only when ESPN has both)
        if ($a['reach_in'] !== null && $b['reach_in'] !== null) {
            $a['context']['reach'] = round(self::PARAMS['context']['reach_per_inch'] * ($a['reach_in'] - $b['reach_in']), 3);
            $a['context']['total'] = round($a['context']['total'] + $a['context']['reach'], 3);
        }
        $context = $a['context']['total'] - $b['context']['total'];

        $w     = self::PARAMS['weights'];
        $delta = $w['resume'] * $resume + $w['performance'] * $perf + $w['matchup'] * $matchup + $w['context'] * $context;
        $p     = 1 / (1 + exp(-self::PARAMS['scale'] * $delta));
        $p     = max(self::PARAMS['clamp'][0], min(self::PARAMS['clamp'][1], $p));

        $notes = [];
        foreach (['a' => $a, 'b' => $b] as $side => $f) {
            if (!$f['complete']) {
                $notes[] = "{$f['name']}'s ESPN history is still syncing, so this number will change.";
            }
            if ($f['perf_fights'] === 0) {
                $why = $f['ufc_fights'] === 0
                    ? "all {$f['fights']} of their fights on ESPN are outside the UFC, and ESPN only has stats for UFC fights"
                    : ($f['fights_with_stats'] === 0
                        ? "ESPN returned no stats for their {$f['ufc_fights']} UFC fight(s)"
                        : 'none of their fights with stats have both fighters\' stats and a known duration');
                $notes[] = "No performance stats for {$f['name']}: $why. Performance and matchup are left out for that side.";
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
                'resume'      => ['value' => round($resume, 3),  'weight' => $w['resume']],
                'performance' => ['value' => round($perf, 3),    'weight' => $w['performance'], 'stats' => array_map(fn($v) => round($v, 3), $perfParts)],
                'matchup'     => ['value' => round($matchup, 3), 'weight' => $w['matchup'], 'edges' => array_map(fn($v) => round($v, 3), $edges)],
                'context'     => ['value' => round($context, 3), 'weight' => $w['context']],
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
        // Rows synced before the placeholder fix may still hold 0 / "--".
        $f['reach_in']  = FighterSync::positive($f['reach_in']);
        $f['height_in'] = FighterSync::positive($f['height_in']);
        $f['stance']    = FighterSync::text($f['stance']);

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
            $P   = self::PARAMS['resume'];
            $q   = ($w + 1) / ($w + $l + 2);
            $e   = min(1.0, ($w + $l) / $P['experience_fights']);
            $rec = 0.5 ** (self::years($r['fight_date'], $asOf) / $P['recency_half_life_years']);
            $lg  = ($r['league'] ?? 'ufc') === 'ufc' ? 1.0 : $P['league_weight_other'];
            $m   = self::methodMultiplier((string) $r['method']);
            $t   = !self::truthy($r['is_title']) ? 1.0 : ($r['title_type'] === 'interim' ? $P['title']['interim'] : $P['title']['undisputed']);
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
            "SELECT fi.fight_date, COALESCE(fi.duration_seconds, CASE WHEN fi.method LIKE 'Decision%' THEN fi.scheduled_rounds * 300 END) AS duration_seconds, fp.opponent_id,
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
              WHERE fp.fighter_id = :id AND fi.status_state = 'post' AND fi.fight_date < :asof
                AND COALESCE(fi.duration_seconds, CASE WHEN fi.method LIKE 'Decision%' THEN fi.scheduled_rounds * 300 END) >= " . (int) self::PARAMS['performance']['min_seconds'] . "
              ORDER BY fi.fight_date DESC
              LIMIT " . (int) self::PARAMS['performance']['fights']
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
            $wt  = (0.5 ** (self::years($s['fight_date'], $asOf) / self::PARAMS['resume']['recency_half_life_years']))
                 * max(self::PARAMS['performance']['min_opponent_quality_weight'], $q);

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
        $C   = self::PARAMS['context'];
        $ctx = [
            'age'      => $age !== null ? $C['age_per_year'] * max(0, $age - $C['age_start']) : 0.0,
            'reach'    => 0.0,   // set in predictMarket(), only when both reaches are known
            'layoff'   => $layoff !== null && $layoff > $C['layoff_months'] ? $C['layoff'] : 0.0,
            'division' => $newDiv ? $C['new_division'] : 0.0,
        ];
        $ctx['total'] = array_sum($ctx);

        $wins = count(array_filter($fights, fn($r) => $r['result'] === 'W'));
        $ufc  = count(array_filter($fights, fn($r) => ($r['league'] ?? 'ufc') === 'ufc'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM fight_participants fp JOIN fights fi ON fi.id = fp.fight_id
              WHERE fp.fighter_id = :id AND fi.status_state = 'post'
                AND EXISTS (SELECT 1 FROM fight_stats s WHERE s.fight_id = fi.id AND s.fighter_id = fp.fighter_id)"
        );
        $stmt->execute([':id' => $fighterId]);
        $withStats = (int) $stmt->fetchColumn();
        return [
            'id' => $fighterId, 'name' => $f['full_name'], 'record' => "{$f['wins']}-{$f['losses']}-{$f['draws']}",
            'age' => $age !== null ? (int) floor($age) : null, 'height_in' => $f['height_in'], 'reach_in' => $f['reach_in'],
            'stance' => $f['stance'], 'weight_class' => $f['weight_class'], 'association' => $f['association'],
            'fights' => count($fights), 'wins_stored' => $wins, 'ufc_fights' => $ufc, 'other_fights' => count($fights) - $ufc,
            'fights_with_stats' => $withStats, 'fights_listed' => $f['fights_listed'],
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
        $this->registerVersion($say);
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
                                      resume_score, performance_score, matchup_score, context_score, delta, components, inputs)
             VALUES (:m, :f, :o, :run, :v, :p, :k, :rs, :ps, :ms, :cs, :d, :c, :in)'
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
                ':in' => json_encode(self::inputs($res), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
        $M = self::PARAMS['resume']['method'];
        return match (true) {
            str_contains($m, 'ko') || str_contains($m, 'submission') => $M['finish'],
            str_contains($m, 'split') || str_contains($m, 'majority') => $M['split_or_majority'],
            default => $M['decision'],
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
