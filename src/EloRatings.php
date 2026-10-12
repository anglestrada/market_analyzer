<?php
declare(strict_types=1);

/**
 * Elo ratings for every fighter, replayed through all stored fights in date order (used by model v2+).
 *
 *   expected(A) = 1 / (1 + 10^((Rb − Ra) / 400))
 *   Ra += K · (result − expected),  K = k × finish × title × league × provisional
 *
 * A fighter first seen mid-career (we only store an opponent's fights since they met our fighters) starts from
 * their record on that day instead of 1500, so a 20-2 veteran doesn't enter as an unknown.
 *
 *   $ratings = EloRatings::for($db, $params)->at($asOf);   // [fighter_id => rating], using fights before $asOf
 *
 * Fights are loaded once per request; asking for dates in increasing order (as backtests do) is a single pass.
 */
final class EloRatings
{
    /** @var array<string, self> */
    private static array $instances = [];

    private ?array $fights = null;
    private int $next = 0;
    private array $ratings = [];
    private array $counts = [];
    private int $upTo = PHP_INT_MIN;

    private function __construct(private PDO $db, private array $p) {}

    public static function for(PDO $db, array $params): self
    {
        $key = spl_object_id($db) . ':' . md5(json_encode($params));
        return self::$instances[$key] ??= new self($db, $params);
    }

    /** Forget loaded fights (after a sync added new ones). */
    public static function reset(): void
    {
        self::$instances = [];
    }

    /** Starting rating from a record [wins, losses] (null = unknown → base). */
    public static function seed(array $p, ?array $record): float
    {
        if ($record === null) {
            return (float) $p['base'];
        }
        [$w, $l] = $record;
        $q = ($w + 1) / ($w + $l + 2);
        $e = min(1.0, ($w + $l) / 10);
        return $p['base'] + $p['seed_scale'] * $e * ($q - 0.5);
    }

    /** @return array<int, float> ratings using only fights before $asOf */
    public function at(DateTimeImmutable $asOf): array
    {
        $this->fights ??= $this->load();
        $t = $asOf->getTimestamp();
        if ($t < $this->upTo) {   // going back in time: replay from the start
            $this->next = 0;
            $this->ratings = [];
            $this->counts = [];
        }
        $this->upTo = $t;

        $n = count($this->fights);
        while ($this->next < $n && $this->fights[$this->next]['ts'] < $t) {
            $this->apply($this->fights[$this->next]);
            $this->next++;
        }
        return $this->ratings;
    }

    private function apply(array $f): void
    {
        $a = $f['a']; $b = $f['b'];
        $this->ratings[$a] ??= self::seed($this->p, $f['a_rec']);
        $this->ratings[$b] ??= self::seed($this->p, $f['b_rec']);
        $sa = match ($f['result']) { 'W' => 1.0, 'L' => 0.0, 'D' => 0.5, default => null };
        if ($sa === null) {
            return;   // no contest
        }
        $ra = $this->ratings[$a];
        $rb = $this->ratings[$b];
        $ea = 1 / (1 + 10 ** (($rb - $ra) / 400));

        $k = $this->p['k'];
        if (preg_match('/\b(t?ko|submission)\b/i', (string) $f['method'])) {
            $k *= $this->p['finish_k'];
        }
        if ($f['title']) {
            $k *= $this->p['title_k'];
        }
        if ($f['league'] !== 'ufc') {
            $k *= $this->p['other_league_k'];
        }
        $ka = $k * (($this->counts[$a] ?? 0) < $this->p['provisional_fights'] ? $this->p['provisional_k'] : 1.0);
        $kb = $k * (($this->counts[$b] ?? 0) < $this->p['provisional_fights'] ? $this->p['provisional_k'] : 1.0);

        $this->ratings[$a] = $ra + $ka * ($sa - $ea);
        $this->ratings[$b] = $rb + $kb * ((1 - $sa) - (1 - $ea));
        $this->counts[$a] = ($this->counts[$a] ?? 0) + 1;
        $this->counts[$b] = ($this->counts[$b] ?? 0) + 1;
    }

    private function load(): array
    {
        $rows = $this->db->query(
            "SELECT fi.id, fi.fight_date, fi.method, fi.is_title, fi.league,
                    a.fighter_id AS a, a.result, a.wins_before AS aw, a.losses_before AS al,
                    b.fighter_id AS b, b.wins_before AS bw, b.losses_before AS bl
               FROM fights fi
               JOIN fight_participants a ON a.fight_id = fi.id
               JOIN fight_participants b ON b.fight_id = fi.id AND b.fighter_id > a.fighter_id
              WHERE fi.status_state = 'post' AND a.result IS NOT NULL AND fi.fight_date IS NOT NULL
              ORDER BY fi.fight_date, fi.id"
        )->fetchAll();
        $out = array_map(fn($r) => [
            'ts' => strtotime((string) $r['fight_date']), 'a' => (int) $r['a'], 'b' => (int) $r['b'], 'result' => $r['result'],
            'method' => $r['method'], 'title' => FightModel::truthy($r['is_title']), 'league' => $r['league'] ?? 'ufc',
            'a_rec' => $r['aw'] !== null ? [(int) $r['aw'], (int) $r['al']] : null,
            'b_rec' => $r['bw'] !== null ? [(int) $r['bw'], (int) $r['bl']] : null,
        ], $rows);
        usort($out, fn($x, $y) => $x['ts'] <=> $y['ts']);
        return $out;
    }
}
