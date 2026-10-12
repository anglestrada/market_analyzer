<?php
declare(strict_types=1);

/**
 * Scores the fight model against what actually happened.
 *
 *   resolve()   for every finished fight: the last prediction saved before the fight (source "live"), the Kalshi
 *               price at the same moment, the result, and the scores → prediction_results
 *   backtest()  every model version recomputed with only the data from before each Kalshi fight (source "backtest"),
 *               so a new version can be scored on every past fight straight away
 *   history()   every model version on stored past UFC fights between two fully-synced fighters (source "history"):
 *               many more fights than Kalshi markets, no Kalshi price to compare against
 *   report()    scorecard, calibration, which parts of the formula helped, model vs Kalshi disagreements
 *
 * Scores (per fight, averaged):
 *   Brier    (p − outcome)²          0 = perfect, 0.25 = always saying 50%, lower is better
 *   Log loss −ln(chance given to the winner)   punishes confident misses hard, lower is better
 *   Accuracy picked the winner (p > 50%)
 *   Skill vs Kalshi = 1 − Brier(model) / Brier(Kalshi) on the same fights: > 0 means the model beat the market
 */
final class ModelEvaluator
{
    /** @var FightModel[] version => model */
    private array $models = [];

    public function __construct(private PDO $db, private ?KalshiClient $kalshi = null)
    {
        foreach (FightModel::all($db) as $m) {
            $this->models[$m->version] = $m;
        }
    }

    public static function make(?KalshiClient $kalshi = null): self
    {
        return new self(db(), $kalshi);
    }

    /**
     * The scanner stops re-reading a market once it closes, which is usually before Kalshi settles it.
     * Fetch the settlement ("result": yes / no) for linked markets that closed in the last 7 days.
     */
    public function refreshSettlements(int $limit = 20): int
    {
        if ($this->kalshi === null) {
            return 0;
        }
        $q = $this->db->prepare(
            "SELECT m.id, m.kalshi_market_id, m.raw_data FROM markets m JOIN market_fighters mf ON mf.market_id = m.id
              WHERE m.status <> 'open' AND m.close_time > :since ORDER BY m.close_time DESC"
        );
        $q->execute([':since' => gmdate(DATE_ATOM, time() - 7 * 86400)]);
        $upd = $this->db->prepare('UPDATE markets SET raw_data = :raw, status = :s WHERE id = :id');
        $n = 0;
        foreach ($q->fetchAll() as $m) {
            $raw = $m['raw_data'] ? json_decode((string) $m['raw_data'], true) : [];
            if (in_array(strtolower((string) ($raw['result'] ?? '')), ['yes', 'no'], true) || $n >= $limit) {
                continue;
            }
            try {
                $fresh = $this->kalshi->market((string) $m['kalshi_market_id']);
                $n++;
            } catch (Throwable) {
                continue;
            }
            if (in_array(strtolower((string) ($fresh['result'] ?? '')), ['yes', 'no'], true)) {
                $upd->execute([':raw' => json_encode($fresh, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                               ':s' => KalshiClient::normalizeStatus($fresh['status'] ?? null), ':id' => $m['id']]);
            }
        }
        return $n;
    }

    /** @return int rows added */
    public function resolve(?callable $say = null): int
    {
        $this->refreshSettlements();
        $added = 0;
        foreach ($this->finishedFights() as $f) {
            $q = $this->db->prepare(
                'SELECT DISTINCT model_version FROM predictions WHERE market_id IN (' . implode(',', $f['market_ids']) . ')
                    AND created_at <= :cut'
            );
            $q->execute([':cut' => $f['cutoff']]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $version) {
                if ($this->exists($f['event_id'], (string) $version, 'live')) {
                    continue;
                }
                $pred = $this->lastPrediction($f, (string) $version);
                if ($pred === null) {
                    continue;
                }
                $added += $this->save($f, (string) $version, 'live', $pred);
            }
        }
        if ($say && $added) {
            $say("Model results: $added finished fight(s) scored.");
        }
        return $added;
    }

    /** Scores every model version on every finished Kalshi fight it has no backtest row for yet. @return int */
    public function backtest(?callable $say = null, bool $redo = false, ?string $only = null): int
    {
        $added = 0;
        $fights = $this->finishedFights();
        usort($fights, fn($x, $y) => strcmp($x['as_of'], $y['as_of']));   // chronological: Elo replays once
        foreach ($this->models as $version => $model) {
            if ($only !== null && $version !== $only) {
                continue;
            }
            $model->registerVersion($say);
            $n = 0;
            foreach ($fights as $f) {
                if ($redo) {
                    $this->db->prepare("DELETE FROM prediction_results WHERE event_id = :e AND model_version = :v AND source = 'backtest'")
                        ->execute([':e' => $f['event_id'], ':v' => $version]);
                } elseif ($this->exists($f['event_id'], $version, 'backtest')) {
                    continue;
                }
                // Only data from before the fight: one minute before the earlier of the fight start and ESPN's fight date.
                $asOf = (new DateTimeImmutable($f['as_of'], new DateTimeZone('UTC')))->modify('-60 seconds');
                $res  = $model->predictMarket($f['a_market'], $asOf);
                if ($res['ok']) {
                    $n += $this->save($f, $version, 'backtest', self::fromResult($res, $asOf));
                }
            }
            if ($say && $n) {
                $say("Model backtest ($version): $n fight(s) scored.");
            }
            $added += $n;
        }
        return $added;
    }

    /**
     * Scores every model version on stored past UFC fights where both fighters' full histories are synced
     * (php bin/fighters_sync.php --deep fills in opponents' histories). @return int
     */
    public function history(?callable $say = null, bool $redo = false, ?string $only = null, int $limit = 5000): int
    {
        $rows = $this->db->query(
            "SELECT fi.id AS fight_id, fi.fight_date, fi.weight_class, fi.method, fi.end_round,
                    a.fighter_id AS a_id, a.result, b.fighter_id AS b_id
               FROM fights fi
               JOIN fight_participants a ON a.fight_id = fi.id
               JOIN fight_participants b ON b.fight_id = fi.id AND b.fighter_id > a.fighter_id   -- one row per fight
               JOIN fighters fa ON fa.id = a.fighter_id
               JOIN fighters fb ON fb.id = b.fighter_id
              WHERE fi.status_state = 'post' AND fi.league = 'ufc' AND fi.fight_date IS NOT NULL
                AND a.result IS NOT NULL
                AND fa.history_synced_at IS NOT NULL AND fb.history_synced_at IS NOT NULL
           ORDER BY fi.fight_date, fi.id
              LIMIT " . max(1, $limit)
        )->fetchAll();

        $added = 0;
        foreach ($this->models as $version => $model) {
            if ($only !== null && $version !== $only) {
                continue;
            }
            $model->registerVersion($say);
            if ($redo) {
                $this->db->prepare("DELETE FROM prediction_results WHERE source = 'history' AND model_version = :v")->execute([':v' => $version]);
            }
            $have = $this->db->prepare("SELECT fight_id FROM prediction_results WHERE source = 'history' AND model_version = :v");
            $have->execute([':v' => $version]);
            $done = array_flip(array_map('intval', $have->fetchAll(PDO::FETCH_COLUMN)));
            $n = 0;
            foreach ($rows as $r) {
                if (isset($done[(int) $r['fight_id']])) {
                    continue;
                }
                $asOf = (new DateTimeImmutable((string) $r['fight_date'], new DateTimeZone('UTC')))->modify('-60 seconds');
                $res  = $model->predictPair((int) $r['a_id'], (int) $r['b_id'], $asOf, $r['weight_class'] ?: null);
                if (!$res['ok']) {
                    continue;
                }
                $result = match ($r['result']) { 'W' => 'win', 'L' => 'loss', 'D' => 'draw', default => 'no_contest' };
                $n += $this->save([
                    'event_id' => null, 'a_market' => null, 'b_market' => null, 'fight_id' => (int) $r['fight_id'],
                    'fighter_id' => (int) $r['a_id'], 'opponent_id' => (int) $r['b_id'],
                    'cutoff' => $asOf->format(DATE_ATOM), 'cutoff_source' => 'espn',
                    'outcome' => in_array($result, ['win', 'loss'], true) ? ($result === 'win' ? 1 : 0) : null,
                    'result_type' => $result, 'outcome_source' => 'espn',
                    'method' => $r['method'], 'end_round' => $r['end_round'] !== null ? (int) $r['end_round'] : null,
                ], $version, 'history', self::fromResult($res, $asOf));
            }
            if ($say && $n) {
                $say("Model history ($version): $n past fight(s) scored.");
            }
            $added += $n;
        }
        return $added;
    }

    /** A FightModel result → the row values save() needs. */
    private static function fromResult(array $res, DateTimeImmutable $asOf): array
    {
        $part = fn(string $k) => $res['parts'][$k]['value'] ?? null;
        return [
            'id' => null, 'created_at' => $asOf->format(DATE_ATOM), 'p' => (float) $res['p'],
            'resume' => $part('resume'), 'performance' => $part('performance'), 'matchup' => $part('matchup'),
            'context' => $part('context'), 'rating' => $part('rating'), 'age' => $part('age'),
            'delta' => $res['delta'], 'inputs' => FightModel::inputs($res),
        ];
    }

    /* ------------------------------------------------------------------ finished fights */

    /**
     * Fights (Kalshi events) with a known result and at least one market linked to an ESPN fighter.
     * @return array<int, array>
     */
    private function finishedFights(): array
    {
        $rows = $this->db->query(
            "SELECT e.id AS event_id, e.event_title, e.event_start_time, e.espn_data,
                    m.id AS market_id, m.yes_subtitle, m.status, m.raw_data, mf.fighter_id, mf.opponent_id,
                    uf.fight_date AS espn_fight_date
               FROM events e
               JOIN markets m ON m.event_id = e.id
          LEFT JOIN market_fighters mf ON mf.market_id = m.id
          LEFT JOIN fights uf ON uf.id = mf.upcoming_fight_id
              WHERE e.sport = 'UFC'
                AND EXISTS (SELECT 1 FROM markets m2 JOIN market_fighters x ON x.market_id = m2.id WHERE m2.event_id = e.id)
                AND NOT EXISTS (SELECT 1 FROM markets m3 WHERE m3.event_id = e.id AND m3.status = 'open')
           ORDER BY e.id, m.id"
        )->fetchAll();

        $events = [];
        foreach ($rows as $r) {
            $events[(int) $r['event_id']][] = $r;
        }
        $out = [];
        foreach ($events as $eventId => $markets) {
            $linked = array_values(array_filter($markets, fn($m) => $m['fighter_id'] !== null));
            $a = $linked[0];
            $b = null;
            foreach ($markets as $m) {
                if ((int) $m['market_id'] !== (int) $a['market_id']) {
                    $b ??= $m;
                }
            }
            $result = $this->result($a, $b);
            if ($result === null) {
                continue;   // not settled yet
            }
            $espn    = $a['espn_data'] ? json_decode((string) $a['espn_data'], true) : null;
            $start   = is_array($espn) && !empty($espn['start']) ? strtotime((string) $espn['start']) : false;
            $cutoff  = $start !== false && $start < time() ? $start : ($a['event_start_time'] ? strtotime($a['event_start_time']) : false);
            if ($cutoff === false) {
                continue;   // no way to tell what "before the fight" means
            }
            $fightDate = $a['espn_fight_date'] ? strtotime($a['espn_fight_date']) : false;
            $out[] = $result + [
                'event_id'      => $eventId,
                'a_market'      => (int) $a['market_id'],
                'b_market'      => $b ? (int) $b['market_id'] : null,
                'market_ids'    => array_map(fn($m) => (int) $m['market_id'], $markets),
                'fighter_id'    => $a['fighter_id'],
                'opponent_id'   => $a['opponent_id'],
                'cutoff'        => gmdate(DATE_ATOM, $cutoff),
                'cutoff_source' => $start !== false && $start === $cutoff ? 'espn' : 'kalshi_event',
                'as_of'         => gmdate(DATE_ATOM, $fightDate !== false ? min($cutoff, $fightDate) : $cutoff),
                'method'        => is_array($espn) ? ($espn['result'] ?? null) : null,
                'end_round'     => is_array($espn) && isset($espn['round']) ? (int) $espn['round'] : null,
            ];
        }
        return $out;
    }

    /**
     * Result for fighter A. ESPN tells draws and no contests apart; otherwise Kalshi's settlement wins (it's what
     * the market actually paid), with ESPN's winner as the fallback.
     * @return array{outcome:?int, result_type:string, outcome_source:string}|null
     */
    private function result(array $a, ?array $b): ?array
    {
        $espn = $a['espn_data'] ? json_decode((string) $a['espn_data'], true) : null;
        $espnOutcome = null;
        if (is_array($espn) && ($espn['state'] ?? '') === 'post') {
            $text = strtolower(($espn['result'] ?? '') . ' ' . ($espn['detail'] ?? ''));
            if (str_contains($text, 'draw')) {
                return ['outcome' => null, 'result_type' => 'draw', 'outcome_source' => 'espn'];
            }
            if (str_contains($text, 'no contest') || preg_match('/\bnc\b/', $text)) {
                return ['outcome' => null, 'result_type' => 'no_contest', 'outcome_source' => 'espn'];
            }
            $ctx = new FightContext((string) $a['yes_subtitle'], [], 'yes');
            foreach ($espn['fighters'] ?? [] as $f) {
                if (($f['winner'] ?? null) === true) {
                    $espnOutcome = $ctx->whichFighter((string) $f['name']) !== null ? 1 : 0;
                }
            }
        }

        $kalshi = null;
        foreach ([[$a, 1], [$b, 0]] as [$m, $ifYes]) {
            if ($m === null || $kalshi !== null) {
                continue;
            }
            $raw = $m['raw_data'] ? json_decode((string) $m['raw_data'], true) : null;
            $res = strtolower((string) ($raw['result'] ?? ''));
            if ($res === 'yes' || $res === 'no') {
                $kalshi = $res === 'yes' ? $ifYes : 1 - $ifYes;
            }
        }
        $outcome = $kalshi ?? $espnOutcome;
        if ($outcome === null) {
            return null;
        }
        return ['outcome' => $outcome, 'result_type' => $outcome === 1 ? 'win' : 'loss',
                'outcome_source' => $kalshi !== null ? 'kalshi' : 'espn'];
    }

    /** The last saved prediction before the fight, as fighter A's chance (B's market counts as 1 − p). */
    private function lastPrediction(array $f, string $version): ?array
    {
        foreach ([[$f['a_market'], false], [$f['b_market'], true]] as [$marketId, $flip]) {
            if ($marketId === null) {
                continue;
            }
            $q = $this->db->prepare(
                'SELECT id, created_at, win_probability, resume_score, performance_score, matchup_score, context_score,
                        rating_score, age_score, delta, inputs
                   FROM predictions WHERE market_id = :m AND model_version = :v AND created_at <= :cut
               ORDER BY created_at DESC LIMIT 1'
            );
            $q->execute([':m' => $marketId, ':v' => $version, ':cut' => $f['cutoff']]);
            if ($r = $q->fetch()) {
                $sign = $flip ? -1 : 1;
                $num  = fn($v) => $v === null ? null : $sign * (float) $v;
                return [
                    'id' => (int) $r['id'], 'created_at' => $r['created_at'],
                    'p' => $flip ? 1 - (float) $r['win_probability'] : (float) $r['win_probability'],
                    'resume' => $num($r['resume_score']), 'performance' => $num($r['performance_score']),
                    'matchup' => $num($r['matchup_score']), 'context' => $num($r['context_score']), 'delta' => $num($r['delta']),
                    'rating' => $num($r['rating_score']), 'age' => $num($r['age_score']),
                    'inputs' => $r['inputs'] ? json_decode((string) $r['inputs'], true) + ($flip ? ['flipped' => true] : []) : null,
                ];
            }
        }
        return null;
    }

    private function save(array $f, string $version, string $source, array $pred): int
    {
        $price = function (?int $marketId) use ($f): ?float {
            if ($marketId === null) {
                return null;
            }
            $q = $this->db->prepare('SELECT yes_price FROM market_snapshots WHERE market_id = :m AND captured_at <= :cut
                                      AND yes_price IS NOT NULL ORDER BY captured_at DESC LIMIT 1');
            $q->execute([':m' => $marketId, ':cut' => $f['cutoff']]);
            $v = $q->fetchColumn();
            return $v === false ? null : (float) $v;
        };
        $ka = $price($f['a_market']);
        $kb = $price($f['b_market']);
        // Two separate markets don't add up to exactly 100%; scale them so they do.
        $k = ($ka !== null && $kb !== null && $ka + $kb > 0) ? $ka / ($ka + $kb) : ($ka ?? ($kb !== null ? 1 - $kb : null));

        $y  = $f['outcome'];
        $sc = fn(?float $p) => ($p === null || $y === null) ? [null, null, null] : self::scores($p, $y);
        [$mb, $ml, $mc] = $sc($pred['p']);
        [$kbr, $kl, $kc] = $sc($k);

        $stmt = $this->db->prepare(
            'INSERT INTO prediction_results (event_id, market_id, fight_id, fighter_id, opponent_id, model_version, source, prediction_id,
                    predicted_at, cutoff_at, cutoff_source, model_p, kalshi_p, kalshi_p_raw, outcome, result_type, outcome_source,
                    method, end_round, model_brier, model_logloss, model_correct, kalshi_brier, kalshi_logloss, kalshi_correct,
                    resume_score, performance_score, matchup_score, context_score, rating_score, age_score, delta, inputs)
             VALUES (:e, :m, :fid, :f, :o, :v, :src, :pid, :pat, :cut, :cs, :p, :k, :kr, :y, :rt, :os, :meth, :rnd,
                     :mb, :ml, :mc, :kb, :kl, :kc, :rs, :ps, :ms, :cx, :rat, :age, :d, :in)
             ON CONFLICT DO NOTHING'
        );
        $stmt->execute([
            ':e' => $f['event_id'], ':m' => $f['a_market'], ':fid' => $f['fight_id'] ?? null, ':f' => $f['fighter_id'], ':o' => $f['opponent_id'],
            ':v' => $version, ':src' => $source, ':pid' => $pred['id'], ':pat' => $pred['created_at'],
            ':cut' => $f['cutoff'], ':cs' => $f['cutoff_source'],
            ':p' => round($pred['p'], 4), ':k' => $k !== null ? round($k, 4) : null, ':kr' => $ka !== null ? round($ka, 4) : null,
            ':y' => $y, ':rt' => $f['result_type'], ':os' => $f['outcome_source'], ':meth' => $f['method'], ':rnd' => $f['end_round'],
            ':mb' => $mb, ':ml' => $ml, ':mc' => $mc, ':kb' => $kbr, ':kl' => $kl, ':kc' => $kc,
            ':rs' => $pred['resume'], ':ps' => $pred['performance'], ':ms' => $pred['matchup'], ':cx' => $pred['context'],
            ':rat' => $pred['rating'] ?? null, ':age' => $pred['age'] ?? null,
            ':d' => $pred['delta'], ':in' => $pred['inputs'] ? json_encode($pred['inputs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
        return $stmt->rowCount();
    }

    /** @return array{0:float,1:float,2:float} Brier, log loss, correct (1 / 0 / 0.5) */
    public static function scores(float $p, int $y): array
    {
        $p = max(0.001, min(0.999, $p));
        return [
            round(($p - $y) ** 2, 5),
            round(-log($y === 1 ? $p : 1 - $p), 5),
            abs($p - 0.5) < 1e-9 ? 0.5 : (float) (($p > 0.5) === ($y === 1)),
        ];
    }

    private function exists(int $eventId, string $version, string $source): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM prediction_results WHERE event_id = :e AND model_version = :v AND source = :s');
        $q->execute([':e' => $eventId, ':v' => $version, ':s' => $source]);
        return (bool) $q->fetchColumn();
    }

    /* ------------------------------------------------------------------ report */

    /**
     * @return array{scorecard:array, calibration:array, parts:array, disagreements:array, fights:array}
     */
    public function report(?string $version = null, ?string $source = null): array
    {
        $where  = ['outcome IS NOT NULL'];
        $params = [];
        if ($version) { $where[] = 'model_version = :v'; $params[':v'] = $version; }
        if ($source)  { $where[] = 'source = :s';        $params[':s'] = $source; }
        $q = $this->db->prepare(
            'SELECT r.*, e.event_title, fa.full_name AS a_name, fb.full_name AS b_name
               FROM prediction_results r
          LEFT JOIN events e ON e.id = r.event_id
          LEFT JOIN fighters fa ON fa.id = r.fighter_id
          LEFT JOIN fighters fb ON fb.id = r.opponent_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY r.cutoff_at DESC'
        );
        $q->execute($params);
        $rows = $q->fetchAll();

        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['model_version'] . ' · ' . $r['source']][] = $r;
        }
        $avg = fn(array $xs) => $xs ? array_sum($xs) / count($xs) : null;

        $scorecard = [];
        foreach ($groups as $key => $g) {
            $withK = array_filter($g, fn($r) => $r['kalshi_brier'] !== null);
            $mbK   = $avg(array_map(fn($r) => (float) $r['model_brier'], $withK));
            $kbK   = $avg(array_map(fn($r) => (float) $r['kalshi_brier'], $withK));
            $scorecard[$key] = [
                'fights'          => count($g),
                'model_accuracy'  => $avg(array_map(fn($r) => (float) $r['model_correct'], $g)),
                'model_brier'     => $avg(array_map(fn($r) => (float) $r['model_brier'], $g)),
                'model_logloss'   => $avg(array_map(fn($r) => (float) $r['model_logloss'], $g)),
                'with_kalshi'     => count($withK),
                'kalshi_accuracy' => $avg(array_map(fn($r) => (float) $r['kalshi_correct'], $withK)),
                'kalshi_brier'    => $kbK,
                'kalshi_logloss'  => $avg(array_map(fn($r) => (float) $r['kalshi_logloss'], $withK)),
                'skill_vs_kalshi' => ($mbK !== null && $kbK) ? 1 - $mbK / $kbK : null,
            ];
        }

        // Calibration: of the fights where the model's favourite had 60–70%, did ~65% of them win?
        $buckets = [[0.5, 0.6], [0.6, 0.7], [0.7, 0.8], [0.8, 0.9], [0.9, 1.01]];
        $calibration = [];
        foreach ($groups as $key => $g) {
            foreach ($buckets as [$lo, $hi]) {
                $in = array_filter($g, function ($r) use ($lo, $hi) {
                    $fav = max((float) $r['model_p'], 1 - (float) $r['model_p']);
                    return $fav >= $lo && $fav < $hi;
                });
                if (!$in) {
                    continue;
                }
                $calibration[$key][] = [
                    'range'     => sprintf('%d–%d%%', $lo * 100, min(100, $hi * 100)),
                    'fights'    => count($in),
                    'predicted' => $avg(array_map(fn($r) => max((float) $r['model_p'], 1 - (float) $r['model_p']), $in)),
                    'actual'    => $avg(array_map(fn($r) => (float) (((float) $r['model_p'] >= 0.5) === ((int) $r['outcome'] === 1)), $in)),
                ];
            }
        }

        // Which parts of the formula pointed at the winner?
        $parts = [];
        foreach ($groups as $key => $g) {
            foreach (['resume_score' => 'resume', 'rating_score' => 'rating', 'performance_score' => 'performance',
                      'matchup_score' => 'matchup', 'context_score' => 'context', 'age_score' => 'age'] as $col => $label) {
                $used = array_filter($g, fn($r) => $r[$col] !== null && abs((float) $r[$col]) > 1e-6);
                $parts[$key][$label] = [
                    'fights_used'      => count($used),
                    'picked_winner'    => $avg(array_map(fn($r) => (float) ((((float) $r[$col]) > 0) === ((int) $r['outcome'] === 1)), $used)),
                    'avg_size'         => $avg(array_map(fn($r) => abs((float) $r[$col]), $used)),
                    'corr_with_result' => self::correlation(array_map(fn($r) => (float) $r[$col], $used),
                                                            array_map(fn($r) => (int) $r['outcome'] * 2 - 1, $used)),
                ];
            }
        }

        // Fights where the model and Kalshi disagreed by 5 pp or more: who was closer?
        $disagreements = [];
        foreach ($groups as $key => $g) {
            $big = array_filter($g, fn($r) => $r['kalshi_p'] !== null && abs((float) $r['model_p'] - (float) $r['kalshi_p']) >= 0.05);
            $disagreements[$key] = [
                'fights'       => count($big),
                'model_closer' => $avg(array_map(fn($r) => (float) (abs((float) $r['model_p'] - (int) $r['outcome'])
                                                                     < abs((float) $r['kalshi_p'] - (int) $r['outcome'])), $big)),
            ];
        }

        return ['scorecard' => $scorecard, 'calibration' => $calibration, 'parts' => $parts,
                'disagreements' => $disagreements, 'fights' => $rows];
    }

    private static function correlation(array $x, array $y): ?float
    {
        $n = count($x);
        if ($n < 3) {
            return null;
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = $sxx = $syy = 0.0;
        foreach ($x as $i => $xi) {
            $sxy += ($xi - $mx) * ($y[$i] - $my);
            $sxx += ($xi - $mx) ** 2;
            $syy += ($y[$i] - $my) ** 2;
        }
        return ($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : null;
    }
}
