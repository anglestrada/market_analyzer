<?php
declare(strict_types=1);

/**
 * ESPN's public (unofficial, undocumented) MMA JSON: fight status, round, clock, result and, when ESPN has
 * them, fight-total stats. Any field may disappear without warning, so every read is defensive and
 * a missing piece is simply left out.
 */
final class EspnClient
{
    public function __construct(
        private string $scoreboardUrl = 'https://site.api.espn.com/apis/site/v2/sports/mma/ufc/scoreboard',
        private string $coreUrl = 'https://sports.core.api.espn.com/v2/sports/mma/leagues/ufc',
    ) {}

    /**
     * Finds the bout on ESPN's scoreboard for the days around $at.
     *
     * @return array{event:string, state:string, completed:bool, round:?int, clock:?string, detail:string,
     *               result:?string, fighters: array<int, array{name:string, winner:?bool, stats:array}>}|null
     */
    public function findFight(FightContext $ctx, DateTimeInterface $at, ?DateTimeInterface $eventStart = null): ?array
    {
        $tz    = new DateTimeZone('America/Los_Angeles');
        $days  = [];
        foreach (array_filter([$at, $eventStart]) as $d) {
            $local = DateTimeImmutable::createFromInterface($d)->setTimezone($tz);
            $days[] = $local->format('Ymd');
            if ((int) $local->format('G') < 6) {
                $days[] = $local->modify('-1 day')->format('Ymd');   // after-midnight main events belong to the previous card
            }
        }

        foreach (array_slice(array_values(array_unique($days)), 0, 3) as $day) {
            $data = Http::json('GET', $this->scoreboardUrl . '?' . http_build_query(['dates' => $day]), [], null, 12);
            foreach ($data['events'] ?? [] as $event) {
                foreach ($event['competitions'] ?? [] as $comp) {
                    $fight = $this->matchCompetition($ctx, $event, $comp);
                    if ($fight !== null) {
                        return $fight;
                    }
                }
            }
        }
        return null;
    }

    private function matchCompetition(FightContext $ctx, array $event, array $comp): ?array
    {
        $fighters = [];
        $names    = '';
        foreach ($comp['competitors'] ?? [] as $c) {
            $a    = $c['athlete'] ?? [];
            $name = (string) ($a['displayName'] ?? $a['fullName'] ?? $a['shortName'] ?? '');
            $names .= ' ' . $name;
            $fighters[] = [
                'id'     => (string) ($c['id'] ?? $a['id'] ?? ''),
                'name'   => $name,
                'winner' => isset($c['winner']) ? (bool) $c['winner'] : null,
                'stats'  => [],
            ];
        }
        // Both fighters must match (or the subject, if the opponent is unknown).
        $hits = array_keys($ctx->mentions($names));
        if (count($fighters) < 2 || count($hits) < min(2, count($ctx->fighters()))) {
            return null;
        }

        $status = $comp['status'] ?? $event['status'] ?? [];
        $type   = $status['type'] ?? [];
        $result = $status['result'] ?? $comp['result'] ?? null;
        $method = is_array($result) ? ($result['displayName'] ?? $result['shortDisplayName'] ?? $result['name'] ?? null) : null;

        $fight = [
            'event'     => (string) ($event['name'] ?? $event['shortName'] ?? ''),
            'state'     => (string) ($type['state'] ?? 'unknown'),          // pre | in | post
            'completed' => (bool) ($type['completed'] ?? false),
            'round'     => isset($status['period']) ? (int) $status['period'] : null,
            'clock'     => $status['displayClock'] ?? null,
            'detail'    => (string) ($type['detail'] ?? $type['shortDetail'] ?? $type['description'] ?? ''),
            'result'    => $method ? (string) $method : null,
            'fighters'  => $fighters,
        ];

        if ($fight['state'] !== 'pre' && !empty($event['id']) && !empty($comp['id'])) {
            foreach ($fight['fighters'] as &$f) {
                $f['stats'] = $this->stats((string) $event['id'], (string) $comp['id'], $f['id']);
            }
            unset($f);
        }
        return $fight;
    }

    /** Fight-total significant strikes / knockdowns / takedowns, if ESPN publishes them. Never throws. */
    private function stats(string $eventId, string $compId, string $competitorId): array
    {
        if ($competitorId === '') {
            return [];
        }
        try {
            $data = Http::json('GET', $this->coreUrl . "/events/$eventId/competitions/$compId/competitors/$competitorId/statistics", [], null, 8);
        } catch (Throwable) {
            return [];
        }
        $wanted = ['sigstrikeslanded' => 'sig', 'knockdowns' => 'kd', 'takedownslanded' => 'td'];
        $out    = [];
        $walk = function (array $node) use (&$walk, &$out, $wanted): void {
            if (isset($node['name'], $node['value']) && is_numeric($node['value'])) {
                $key = $wanted[strtolower((string) $node['name'])] ?? null;
                if ($key !== null && !isset($out[$key])) {
                    $out[$key] = (int) $node['value'];
                }
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($data);
        return $out;
    }
}
