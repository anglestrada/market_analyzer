<?php
declare(strict_types=1);

/**
 * Fight prediction models (see README → Fight prediction). Reads only the database.
 * Several versions run side by side so their accuracy can be compared on the same fights:
 *
 *   v1  Δ = 0.35·Resume + 0.35·Performance + 0.20·Matchup + 0.10·Context,  P = 1 / (1 + e^(−1.6·Δ))
 *   v2  v1's parts re-weighted + an Elo rating + an age-gap term; small stat samples shrunk toward the
 *       division average; recent KO losses count against durability.  P = 1 / (1 + e^(−Δ))
 *
 *   $model = new FightModel($db, 'v2');
 *
 * Every number a version uses is in VERSIONS and is saved with its predictions (model_versions.params).
 * Never change a version's numbers in place: add a new version instead, so old results stay comparable.
 */
final class FightModel
{
    public const DEFAULT_VERSION = 'v1';

    private const V1_PARAMS = [
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
    private const V1_SD = [
        'resume' => 1.5,
        'sig_diff' => 1.6, 'str_def' => 0.07, 'kd15' => 0.40, 'td15' => 1.40, 'td_def' => 0.20,
        'ctrl' => 0.12, 'sub15' => 0.60, 'kd_abs15' => 0.40,
        'edge_strike' => 1.20, 'edge_grapple' => 0.80,
    ];

    /**
     * v2 = v1 + the research-backed changes. Weights are on the final log-odds (scale 1), so they read directly:
     * a part worth +1.0 after weighting moves a 50% fight to 73%. Starting values; fit them with bin/model_fit.php.
     */
    private const V2_PARAMS = [
        'weights'      => ['resume' => 0.35, 'rating' => 0.60, 'performance' => 0.50, 'matchup' => 0.25, 'context' => 0.16, 'age' => 0.06],
        'scale'        => 1.0,
        'clamp'        => [0.05, 0.95],
        'resume'       => [
            'experience_fights' => 10, 'recency_half_life_years' => 3, 'league_weight_other' => 0.6,
            'method' => ['finish' => 1.25, 'split_or_majority' => 0.9, 'decision' => 1.0],
            'title'  => ['undisputed' => 2.0, 'interim' => 1.5],
        ],
        // Elo over every stored fight in date order; a fighter first seen mid-career is seeded from their record then.
        'rating'       => [
            'base' => 1500, 'seed_scale' => 800, 'k' => 32, 'provisional_fights' => 5, 'provisional_k' => 1.5,
            'finish_k' => 1.25, 'title_k' => 1.25, 'other_league_k' => 0.6,
        ],
        // Stats shrunk toward the division average: (n·fighter + k·average) / (n + k)
        'performance'  => ['fights' => 7, 'min_seconds' => 60, 'min_opponent_quality_weight' => 0.3, 'shrink_fights' => 3],
        'matchup'      => ['clamp' => 3.0, 'default_td_defense' => 0.65],
        'context'      => [
            'age_start' => 33, 'age_per_year' => 0.0,   // age handled by the age-gap part instead
            'reach_per_inch' => 0.03, 'layoff_months' => 18, 'layoff' => -0.2, 'new_division' => -0.2,
            'ko_loss_years' => 3, 'ko_loss' => -0.15, 'ko_loss_max' => 2,
        ],
        'age'          => ['per_year_gap' => 1.0],    // part value = opponent's age − fighter's age (years)
    ];

    /** Typical UFC values, used by v2 to shrink small samples. */
    private const V2_MEANS = [
        'sig_diff' => 0.0, 'sig_att_pm' => 8.5, 'str_def' => 0.55, 'kd15' => 0.25, 'td15' => 1.3, 'td_att15' => 3.2,
        'td_def' => 0.65, 'ctrl' => 0.15, 'sub15' => 0.4, 'kd_abs15' => 0.25,
    ];

    public const VERSIONS = [
        'v1' => ['description' => 'Resume + performance + style matchup + context, hand-set starting weights.',
                 'params' => self::V1_PARAMS, 'sd' => self::V1_SD],
        'v2' => ['description' => 'v1 + Elo rating + age gap + small-sample shrinkage + recent KO losses; re-weighted.',
                 'params' => self::V2_PARAMS, 'sd' => self::V1_SD, 'means' => self::V2_MEANS],
    ];

    /** Labels for the breakdown on the prediction page. */
    public const STAT_LABELS = [
        'sig_diff' => 'Sig. strike differential / min', 'str_def' => 'Striking defense', 'kd15' => 'Knockdowns / 15 min',
        'td15' => 'Takedowns / 15 min', 'td_def' => 'Takedown defense', 'ctrl' => 'Control time %',
        'sub15' => 'Submission attempts / 15 min', 'kd_abs15' => 'Knocked down / 15 min',
    ];

    public const PART_LABELS = [
        'resume'      => 'Resume (who they beat, how, how recently)',
        'rating'      => 'Elo rating (strength passed on through opponents)',
        'performance' => 'Performance (fight stats)',
        'matchup'     => 'Style matchup (offense vs. the other\'s defense)',
        'context'     => 'Context (reach, layoff, division, recent KO losses)',
        'age'         => 'Age gap (years younger than the opponent)',
    ];

    public readonly string $version;
    public readonly array $params;
    private readonly array $sd;
    private readonly ?array $means;

    public function __construct(private PDO $db, string $version = self::DEFAULT_VERSION)
    {
        if (!isset(self::VERSIONS[$version])) {
            throw new InvalidArgumentException("Unknown model version $version");
        }
        $this->version = $version;
        $this->params  = self::VERSIONS[$version]['params'];
        $this->sd      = self::VERSIONS[$version]['sd'];
        $this->means   = self::VERSIONS[$version]['means'] ?? null;
    }

    /** @return string[] e.g. ['v1', 'v2'] */
    public static function versions(): array
    {
        return array_keys(self::VERSIONS);
    }

    /** @return FightModel[] one model per version */
    public static function all(PDO $db): array
    {
        return array_map(fn($v) => new self($db, $v), self::versions());
    }

    public function paramsHash(): string
    {
        $v = self::VERSIONS[$this->version];
        // v1's hash is computed exactly as before versions existed, so its logged history keeps matching.
        $data = ['params' => $v['params'], 'sd' => $v['sd']] + (isset($v['means']) ? ['means' => $v['means']] : []);
        return substr(hash('sha256', json_encode($data)), 0, 16);
    }

    /** Records this version's exact algorithm in model_versions (once); flags it if the numbers changed. */
    public function registerVersion(?callable $say = null): void
    {
        $q = $this->db->prepare('SELECT params_hash FROM model_versions WHERE version = :v');
        $q->execute([':v' => $this->version]);
        $hash = $q->fetchColumn();
        if ($hash === false) {
            $v = self::VERSIONS[$this->version];
            $this->db->prepare('INSERT INTO model_versions (version, params, params_hash, description) VALUES (:v, :p, :h, :d)')
                ->execute([':v' => $this->version, ':h' => $this->paramsHash(), ':d' => $v['description'],
                           ':p' => json_encode(array_diff_key($v, ['description' => 1]), JSON_UNESCAPED_SLASHES)]);
        } elseif ($hash !== $this->paramsHash() && $say) {
            $say("WARNING: model {$this->version}'s numbers changed since it was first used. Add a new version instead, so results stay comparable.");
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
            'rating' => $f['rating'] ?? null, 'ko_losses_recent' => $f['ko_losses_recent'] ?? null,
            'perf' => array_map(fn($v) => $v === null ? null : round($v, 4), $f['perf']), 'context' => $f['context'],
        ];
        return ['version' => $res['version'], 'params_hash' => $res['params_hash'], 'a' => $side($res['a']), 'b' => $side($res['b']),
                'parts' => $res['parts'], 'notes' => $res['notes']];
    }

    /**
     * Prediction for one Kalshi market ("Will <fighter> win?").
     * @return array{ok:bool, reason?:string, p?:float, delta?:float, parts?:array, a?:array, b?:array, notes?:array}
     */
    public function predictMarket(int $marketId, ?DateTimeImmutable $asOf = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT mf.*, uf.weight_class AS upcoming_weight_class
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
        return $this->predictPair((int) $link['fighter_id'], (int) $link['opponent_id'], $asOf, $link['upcoming_weight_class'] ?: null);
    }

    /** Fighter A's chance against fighter B on $asOf (default now), using only fights before $asOf. */
    public function predictPair(int $fighterId, int $opponentId, ?DateTimeImmutable $asOf = null, ?string $class = null): array
    {
        $asOf ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $P = $this->params;
        $a = $this->fighterProfile($fighterId, $asOf, $class);
        $b = $this->fighterProfile($opponentId, $asOf, $class);
        if ($a === null || $b === null) {
            return ['ok' => false, 'reason' => 'not_synced'];
        }
        if ($a['fights'] === 0 || $b['fights'] === 0) {
            return ['ok' => false, 'reason' => 'no_history', 'a' => $a, 'b' => $b];
        }
        $parts = [];

        // Resume
        $parts['resume'] = ($a['resume'] - $b['resume']) / $this->sd['resume'];

        // Elo rating (v2+), as a log-odds difference
        if (isset($P['weights']['rating'])) {
            $ratings = EloRatings::for($this->db, $P['rating'])->at($asOf);
            $a['rating'] = round($ratings[$fighterId] ?? EloRatings::seed($P['rating'], $a['wins_before_known'] ?? null), 1);
            $b['rating'] = round($ratings[$opponentId] ?? EloRatings::seed($P['rating'], $b['wins_before_known'] ?? null), 1);
            $parts['rating'] = ($a['rating'] - $b['rating']) * log(10) / 400;
        }

        // Performance: average z-difference over the stats both fighters have
        $perfParts = [];
        foreach (array_keys(self::STAT_LABELS) as $k) {
            $x = $a['perf'][$k] ?? null;
            $y = $b['perf'][$k] ?? null;
            if ($x === null || $y === null) {
                continue;
            }
            $z = ($x - $y) / $this->sd[$k];
            $perfParts[$k] = $k === 'kd_abs15' ? -$z : $z;   // being knocked down is bad
        }
        $parts['performance'] = $perfParts ? array_sum($perfParts) / count($perfParts) : 0.0;

        // Style matchup: A's offense against B's defense, minus the reverse
        $matchup = 0.0;
        $edges   = [];
        if (isset($a['perf']['sig_att_pm'], $b['perf']['sig_att_pm'], $a['perf']['str_def'], $b['perf']['str_def'])) {
            $edges['striking'] = $a['perf']['sig_att_pm'] * (1 - $b['perf']['str_def'])
                               - $b['perf']['sig_att_pm'] * (1 - $a['perf']['str_def']);
            $matchup += $edges['striking'] / $this->sd['edge_strike'];
        }
        if (isset($a['perf']['td_att15'], $b['perf']['td_att15'])) {
            $tdDef = $P['matchup']['default_td_defense'];
            $edges['grappling'] = $a['perf']['td_att15'] * (1 - ($b['perf']['td_def'] ?? $tdDef))
                                - $b['perf']['td_att15'] * (1 - ($a['perf']['td_def'] ?? $tdDef));
            $matchup += $edges['grappling'] / $this->sd['edge_grapple'];
        }
        $parts['matchup'] = max(-$P['matchup']['clamp'], min($P['matchup']['clamp'], $matchup));

        // Context (reach counts only when ESPN has both)
        if ($a['reach_in'] !== null && $b['reach_in'] !== null) {
            $a['context']['reach'] = round($P['context']['reach_per_inch'] * ($a['reach_in'] - $b['reach_in']), 3);
            $a['context']['total'] = round($a['context']['total'] + $a['context']['reach'], 3);
        }
        $parts['context'] = $a['context']['total'] - $b['context']['total'];

        // Age gap (v2+): positive when A is younger
        if (isset($P['weights']['age'])) {
            $parts['age'] = ($a['age_years'] !== null && $b['age_years'] !== null)
                ? ($b['age_years'] - $a['age_years']) * $P['age']['per_year_gap'] : 0.0;
        }

        $delta = 0.0;
        foreach ($P['weights'] as $k => $w) {
            $delta += $w * ($parts[$k] ?? 0.0);
        }
        $p = 1 / (1 + exp(-$P['scale'] * $delta));
        $p = max($P['clamp'][0], min($P['clamp'][1], $p));

        $notes = [];
        foreach ([$a, $b] as $f) {
            if (!$f['complete']) {
                $notes[] = "{$f['name']}'s ESPN history is still syncing, so this number will change.";
            }
            if ($f['perf_fights'] === 0) {
                $why = $f['ufc_fights'] === 0
                    ? "all {$f['fights']} of their fights on ESPN are outside the UFC, and ESPN only has stats for UFC fights"
                    : ($f['fights_with_stats'] === 0
                        ? "ESPN returned no stats for their {$f['ufc_fights']} UFC fight(s)"
                        : 'none of their fights with stats have both fighters\' stats and a known duration');
                $notes[] = $this->means
                    ? "No performance stats for {$f['name']}: $why. Their stats are assumed to be division average."
                    : "No performance stats for {$f['name']}: $why. Performance and matchup are left out for that side.";
            } elseif ($f['perf_fights'] < 3) {
                $notes[] = "{$f['name']} has only {$f['perf_fights']} fight(s) with ESPN stats"
                    . ($this->means ? ', so their stats are pulled toward the division average.' : '.');
            }
            if ($f['approx_records'] > 0) {
                $notes[] = "{$f['approx_records']} of {$f['name']}'s opponents use their current record (record on the day not known yet).";
            }
        }

        $out = [];
        foreach ($P['weights'] as $k => $w) {
            $out[$k] = ['value' => round($parts[$k] ?? 0.0, 3), 'weight' => $w];
        }
        $out['performance']['stats'] = array_map(fn($v) => round($v, 3), $perfParts);
        $out['matchup']['edges']     = array_map(fn($v) => round($v, 3), $edges);

        return [
            'ok'          => true,
            'version'     => $this->version,
            'params_hash' => $this->paramsHash(),
            'complete'    => $a['complete'] && $b['complete'],
            'p'           => round($p, 4),
            'delta'       => round($delta, 4),
            'parts'       => $out,
            'a'           => $a,
            'b'           => $b,
            'notes'       => $notes,
        ];
    }

    /**
     * Everything the model knows about one fighter on $asOf.
     * @return array|null null when the fighter's profile hasn't been synced yet
     */
    public function fighterProfile(int $fighterId, DateTimeImmutable $asOf, ?string $upcomingClass = null): ?array
    {
        $P = $this->params;
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
                    fi.duration_seconds, fi.end_round, fp.result, fp.wins_before, fp.losses_before,
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
        $R = $P['resume'];
        $resume = 0.0;
        $approx = 0;
        $resumeRows = [];
        $koLosses = 0;
        foreach ($fights as $r) {
            if ($r['opp_w'] !== null) {
                $w = (int) $r['opp_w']; $l = (int) $r['opp_l'];
            } else {
                $approx++;
                $w = (int) $r['opp_cur_w']; $l = (int) $r['opp_cur_l'];
            }
            $q   = ($w + 1) / ($w + $l + 2);
            $e   = min(1.0, ($w + $l) / $R['experience_fights']);
            $yrs = self::years($r['fight_date'], $asOf);
            $rec = 0.5 ** ($yrs / $R['recency_half_life_years']);
            $lg  = ($r['league'] ?? 'ufc') === 'ufc' ? 1.0 : $R['league_weight_other'];
            $m   = $this->methodMultiplier((string) $r['method']);
            $t   = !self::truthy($r['is_title']) ? 1.0 : ($r['title_type'] === 'interim' ? $R['title']['interim'] : $R['title']['undisputed']);
            $pts = match ($r['result']) {
                'W'     => $q * $e * $m * $t * $rec * $lg,
                'L'     => -(1 - $q) * $rec * $lg,
                default => 0.0,
            };
            $resume += $pts;
            if ($r['result'] === 'L' && preg_match('/\b(t?ko)\b/i', (string) $r['method'])
                && $yrs <= ($P['context']['ko_loss_years'] ?? 0)) {
                $koLosses++;
            }
            $resumeRows[] = [
                'date' => $r['fight_date'], 'opponent' => $r['opp_name'], 'result' => $r['result'], 'method' => $r['method'],
                'opp_record' => "$w-$l", 'opp_record_exact' => $r['opp_w'] !== null, 'title' => self::truthy($r['is_title']),
                'league' => $r['league'], 'points' => round($pts, 3),
            ];
        }

        // ---- 2. Performance (UFC fights with stats for both fighters, most recent first)
        $dur = "COALESCE(fi.duration_seconds, CASE WHEN fi.method LIKE 'Decision%' THEN fi.scheduled_rounds * 300 END)";
        $stmt = $this->db->prepare(
            "SELECT fi.fight_date, $dur AS duration_seconds, fp.opponent_id,
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
                AND $dur >= " . (int) $P['performance']['min_seconds'] . "
              ORDER BY fi.fight_date DESC
              LIMIT " . (int) $P['performance']['fights']
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
            $wt  = (0.5 ** (self::years($s['fight_date'], $asOf) / $R['recency_half_life_years']))
                 * max($P['performance']['min_opponent_quality_weight'], $q);

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
        $rawPerf = $perf;

        // v2+: shrink toward the division average — a fighter with one great UFC fight isn't treated as fully known.
        if ($this->means) {
            $n = count($statRows);
            $k = (float) $P['performance']['shrink_fights'];
            foreach ($this->means as $key => $mean) {
                $x = $perf[$key] ?? null;
                $perf[$key] = $x === null ? $mean : ($n * $x + $k * $mean) / ($n + $k);
            }
        }
        $landed = array_sum($shares);
        $shares = $landed > 0 ? array_map(fn($v) => round($v / $landed, 3), $shares) : null;

        // ---- 3. Style label (from the raw stats)
        $style = null;
        if ($rawPerf) {
            $style = match (true) {
                ($rawPerf['td15'] ?? 0) >= 2.5 || ($rawPerf['ctrl'] ?? 0) >= 0.35 => 'Wrestler',
                ($rawPerf['sub15'] ?? 0) >= 1.0                                     => 'Grappler',
                ($shares['ground'] ?? 0) >= 0.30                                    => 'Ground and pound',
                ($rawPerf['kd15'] ?? 0) >= 0.6                                      => 'Power striker',
                default                                                             => 'Striker',
            };
        }

        // ---- 4. Context
        $C       = $P['context'];
        $age     = $f['date_of_birth'] ? self::years($f['date_of_birth'], $asOf) : null;
        $last    = $fights[0] ?? null;
        $layoff  = $last ? self::years($last['fight_date'], $asOf) * 12 : null;   // months
        $newDiv  = $upcomingClass && $last && $last['weight_class']
            && !preg_match('/' . preg_quote(self::division((string) $upcomingClass), '/') . '/i', (string) $last['weight_class']);
        $ctx = [
            'age'      => $age !== null ? $C['age_per_year'] * max(0, $age - $C['age_start']) : 0.0,
            'reach'    => 0.0,   // set in predictPair(), only when both reaches are known
            'layoff'   => $layoff !== null && $layoff > $C['layoff_months'] ? $C['layoff'] : 0.0,
            'division' => $newDiv ? $C['new_division'] : 0.0,
        ];
        if (isset($C['ko_loss'])) {
            $ctx['ko_losses'] = $C['ko_loss'] * min($koLosses, $C['ko_loss_max']);
        }
        $ctx['total'] = array_sum($ctx);

        $wins = count(array_filter($fights, fn($r) => $r['result'] === 'W'));
        $ufc  = count(array_filter($fights, fn($r) => ($r['league'] ?? 'ufc') === 'ufc'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM fight_participants fp JOIN fights fi ON fi.id = fp.fight_id
              WHERE fp.fighter_id = :id AND fi.status_state = 'post' AND fi.fight_date < :asof
                AND EXISTS (SELECT 1 FROM fight_stats s WHERE s.fight_id = fi.id AND s.fighter_id = fp.fighter_id)"
        );
        $stmt->execute([':id' => $fighterId, ':asof' => $asOf->format('c')]);
        $withStats = (int) $stmt->fetchColumn();

        // Record before the oldest stored fight (for seeding an Elo rating when a fighter has no rated fights).
        $oldest = $fights ? end($fights) : null;
        $seedRecord = ($oldest && $oldest['wins_before'] !== null) ? [(int) $oldest['wins_before'], (int) $oldest['losses_before']] : null;

        return [
            'id' => $fighterId, 'name' => $f['full_name'], 'record' => "{$f['wins']}-{$f['losses']}-{$f['draws']}",
            'age' => $age !== null ? (int) floor($age) : null, 'age_years' => $age,
            'height_in' => $f['height_in'], 'reach_in' => $f['reach_in'],
            'stance' => $f['stance'], 'weight_class' => $f['weight_class'], 'association' => $f['association'],
            'fights' => count($fights), 'wins_stored' => $wins, 'ufc_fights' => $ufc, 'other_fights' => count($fights) - $ufc,
            'fights_with_stats' => $withStats, 'fights_listed' => $f['fights_listed'],
            'resume' => round($resume, 4), 'resume_rows' => $resumeRows, 'approx_records' => $approx,
            'perf' => $perf, 'raw_perf' => $rawPerf, 'perf_fights' => count($statRows), 'shares' => $shares, 'style' => $style,
            'context' => array_map(fn($v) => round($v, 3), $ctx), 'ko_losses_recent' => $koLosses,
            'layoff_months' => $layoff !== null ? (int) round($layoff) : null,
            'complete' => $f['history_synced_at'] !== null,
            'wins_before_known' => $seedRecord,
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
                                      resume_score, performance_score, matchup_score, context_score, rating_score, age_score,
                                      delta, components, inputs)
             VALUES (:m, :f, :o, :run, :v, :p, :k, :rs, :ps, :ms, :cs, :rt, :ag, :d, :c, :in)'
        );
        $saved = 0;
        foreach ($rows as $r) {
            $res = $this->predictMarket((int) $r['market_id']);
            if (!$res['ok'] || !$res['complete']) {
                continue;   // don't chart half-synced numbers
            }
            $last->execute([':m' => $r['market_id'], ':v' => $this->version]);
            $prev = $last->fetch();
            if ($prev && abs((float) $prev['win_probability'] - $res['p']) < 0.005 && strtotime($prev['created_at']) > time() - 6 * 3600) {
                continue;
            }
            $part = fn(string $k) => $res['parts'][$k]['value'] ?? null;
            $ins->execute([
                ':m' => $r['market_id'], ':f' => $r['fighter_id'], ':o' => $r['opponent_id'], ':run' => $syncRunId,
                ':v' => $this->version, ':p' => $res['p'], ':k' => $r['yes_price'],
                ':rs' => $part('resume'), ':ps' => $part('performance'), ':ms' => $part('matchup'), ':cs' => $part('context'),
                ':rt' => $part('rating'), ':ag' => $part('age'), ':d' => $res['delta'],
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
            $say("Fight predictions ({$this->version}): $saved updated.");
        }
        return $saved;
    }

    /* ------------------------------------------------------------------ helpers */

    public function methodMultiplier(string $method): float
    {
        $m = strtolower($method);
        $M = $this->params['resume']['method'];
        return match (true) {
            str_contains($m, 'ko') || str_contains($m, 'submission') => $M['finish'],
            str_contains($m, 'split') || str_contains($m, 'majority') => $M['split_or_majority'],
            default => $M['decision'],
        };
    }

    public static function years(?string $from, DateTimeImmutable $to): float
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

    public static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }
}
