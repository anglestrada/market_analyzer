<?php
declare(strict_types=1);

/**
 * ESPN's public JSON (no API key; unofficial and undocumented, so every read is defensive and a
 * missing piece is simply left out).
 *
 *   site  …/mma/ufc/scoreboard?dates=YYYYMMDD     fight cards: fighters, records, weight class, status, result
 *   site  …/mma/news, …/mma/ufc/athletes/{id}/news MMA news + each fighter's own feed
 *   core  …/events/{e}/competitions/{c}/plays      play-by-play (knockdowns, takedowns, strikes)
 *   core  …/competitors/{id}/statistics            fight-total stats
 *   core  …/competitors/{id}/linescores            round-by-round judges' scores
 *   core  …/competitions/{c}/odds                  sportsbook moneylines
 */
final class EspnClient
{
    /** @var array<string, array> scoreboard per day, cached for this run */
    private array $boards = [];

    public function __construct(
        private string $siteUrl = 'https://site.api.espn.com/apis/site/v2/sports/mma',
        private string $coreUrl = 'https://sports.core.api.espn.com/v2/sports/mma/leagues/ufc',
    ) {}

    /** ESPN files a card under its US date; check Eastern and the app's time zone, and the day before for late main events. */
    public static function candidateDays(?DateTimeInterface ...$times): array
    {
        $days = [];
        foreach (array_filter($times) as $t) {
            foreach (array_unique(['America/New_York', APP_TIMEZONE]) as $tz) {
                $local  = DateTimeImmutable::createFromInterface($t)->setTimezone(new DateTimeZone($tz));
                $days[] = $local->format('Ymd');
                if ((int) $local->format('G') < 6) {
                    $days[] = $local->modify('-1 day')->format('Ymd');
                }
            }
        }
        return array_values(array_unique($days));
    }

    /** Set when ESPN answers 429/403: no more ESPN calls for the rest of this scan. */
    private ?string $blocked = null;

    /** @var array<string, array> news feed responses, cached for this run */
    private array $feeds = [];

    public function scoreboard(string $day): array
    {
        if (!array_key_exists($day, $this->boards)) {
            try {
                $this->boards[$day] = $this->get($this->siteUrl . '/ufc/scoreboard?' . http_build_query(['dates' => $day]), 12);
            } catch (Throwable $e) {
                $this->boards[$day] = $e;   // don't ask again for every bout on the card
            }
        }
        if ($this->boards[$day] instanceof Throwable) {
            throw $this->boards[$day];
        }
        return $this->boards[$day];
    }

    public function requestCount(): int
    {
        return array_sum(array_intersect_key(Http::$counts, array_flip(['site.api.espn.com', 'sports.core.api.espn.com'])));
    }

    /** Every ESPN request goes through here so a rate limit stops the rest of the scan's ESPN calls. */
    private function get(string $url, int $timeout = 10): array
    {
        if ($this->blocked !== null) {
            throw new HttpException("Skipped: ESPN refused an earlier request this scan ({$this->blocked}); trying again next scan.", 429);
        }
        try {
            return Http::json('GET', $url, [], null, $timeout);
        } catch (HttpException $e) {
            if (in_array($e->httpStatus, [403, 429], true)) {
                $this->blocked = "HTTP {$e->httpStatus}";
            }
            throw $e;
        }
    }

    /**
     * The bout on ESPN's scoreboard for any of $days, or null.
     *
     * @return array{event_id:string, comp_id:string, event:string, venue:?string, weight_class:?string, rounds:?int,
     *               state:string, completed:bool, round:?int, clock:?string, detail:string, result:?string,
     *               fighters: array<int, array{id:string, athlete_id:string, name:string, record:?string, winner:?bool, home_away:?string}>}|null
     */
    public function findFight(FightContext $ctx, array $days): ?array
    {
        foreach (array_slice($days, 0, 4) as $day) {
            foreach ($this->scoreboard($day)['events'] ?? [] as $event) {
                foreach ($event['competitions'] ?? [] as $comp) {
                    if ($fight = $this->matchCompetition($ctx, $event, $comp)) {
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
                'id'         => (string) ($c['id'] ?? ''),
                'athlete_id' => (string) ($a['id'] ?? $c['id'] ?? ''),
                'name'       => $name,
                'record'     => $c['records'][0]['summary'] ?? $a['record'] ?? null,
                'winner'     => isset($c['winner']) ? (bool) $c['winner'] : null,
                'home_away'  => $c['homeAway'] ?? null,
            ];
        }
        // Both fighters must match (or the subject alone, if the opponent is unknown).
        if (count($fighters) < 2 || count($ctx->mentions($names)) < min(2, count($ctx->fighters()))) {
            return null;
        }

        $status = $comp['status'] ?? $event['status'] ?? [];
        $type   = $status['type'] ?? [];
        $result = $status['result'] ?? $comp['result'] ?? null;
        $method = is_array($result) ? ($result['displayName'] ?? $result['shortDisplayName'] ?? $result['name'] ?? null) : null;
        $target = is_array($result) ? ($result['target']['displayName'] ?? $result['description'] ?? null) : null;

        return [
            'event_id'     => (string) ($event['id'] ?? ''),
            'comp_id'      => (string) ($comp['id'] ?? ''),
            'event'        => (string) ($event['name'] ?? $event['shortName'] ?? ''),
            'venue'        => $comp['venue']['fullName'] ?? $event['venues'][0]['fullName'] ?? null,
            'weight_class' => $comp['type']['text'] ?? $comp['type']['abbreviation'] ?? $comp['note'] ?? null,
            'rounds'       => isset($comp['format']['regulation']['periods']) ? (int) $comp['format']['regulation']['periods'] : null,
            'start'        => $comp['date'] ?? $comp['startDate'] ?? $event['date'] ?? null,
            'state'        => (string) ($type['state'] ?? 'unknown'),          // pre | in | post
            'completed'    => (bool) ($type['completed'] ?? false),
            'round'        => isset($status['period']) ? (int) $status['period'] : null,
            'clock'        => $status['displayClock'] ?? null,
            'detail'       => (string) ($type['detail'] ?? $type['shortDetail'] ?? $type['description'] ?? ''),
            'result'       => $method ? trim($method . ($target && $target !== $method ? " ($target)" : '')) : null,
            'fighters'     => $fighters,
        ];
    }

    /* ------------------------------------------------------------------ core API drill-downs (never throw) */

    /** Fight-total stats per fighter: sig = significant strikes, kd, td, sub, ctrl (display value). */
    public function stats(array $fight): array
    {
        $alias = [
            'sigstrikeslanded' => 'sig', 'significantstrikeslanded' => 'sig', 'sigstrikesattempted' => 'sig_att',
            'totalstrikeslanded' => 'total', 'knockdowns' => 'kd', 'takedownslanded' => 'td', 'takedownsattempted' => 'td_att',
            'submissionattempts' => 'sub', 'timeincontrol' => 'ctrl', 'controltime' => 'ctrl', 'groundcontroltime' => 'ctrl',
        ];
        $out = [];
        foreach ($fight['fighters'] as $f) {
            $data = $this->core("/events/{$fight['event_id']}/competitions/{$fight['comp_id']}/competitors/{$f['id']}/statistics");
            $s    = [];
            self::walk($data, function (array $n) use (&$s, $alias) {
                $key = $alias[strtolower((string) ($n['name'] ?? ''))] ?? null;
                if ($key !== null && !isset($s[$key])) {
                    $s[$key] = $key === 'ctrl' ? (string) ($n['displayValue'] ?? $n['value'] ?? '') : (int) round((float) ($n['value'] ?? 0));
                }
            });
            $out[$f['id']] = $s;
        }
        return $out;
    }

    /** Round-by-round judges' totals per fighter, e.g. [1 => 10, 2 => 9]. */
    public function linescores(array $fight): array
    {
        $out = [];
        foreach ($fight['fighters'] as $f) {
            $data = $this->core("/events/{$fight['event_id']}/competitions/{$fight['comp_id']}/competitors/{$f['id']}/linescores");
            foreach ($data['items'] ?? [] as $i => $item) {
                $p     = $item['period'] ?? null;
                $round = (int) (is_array($p) ? ($p['number'] ?? $i + 1) : ($p ?? $i + 1));
                if (isset($item['value']) && is_numeric($item['value'])) {
                    $out[$f['id']][$round] = (float) $item['value'];
                }
            }
        }
        return $out;
    }

    /** Pre-fight moneylines: ['provider' => 'DraftKings', 'lines' => [competitorId => -165, …]]. */
    public function odds(array $fight): ?array
    {
        $data = $this->core("/events/{$fight['event_id']}/competitions/{$fight['comp_id']}/odds");
        $item = $data['items'][0] ?? null;
        if (!is_array($item)) {
            return null;
        }
        $lines = [];
        foreach ($fight['fighters'] as $f) {
            $side = $f['home_away'];
            $o    = $side ? ($item[$side . 'AthleteOdds'] ?? $item[$side . 'TeamOdds'] ?? null) : null;
            if (is_array($o) && isset($o['moneyLine']) && is_numeric($o['moneyLine'])) {
                $lines[$f['id']] = (int) $o['moneyLine'];
            }
        }
        return $lines ? ['provider' => (string) ($item['provider']['name'] ?? 'Sportsbook'), 'lines' => $lines] : null;
    }

    /**
     * Play-by-play, oldest first.
     * @return array<int, array{text:string, type:string, round:?int, clock:?string, at:?string, athlete_id:?string}>
     */
    public function plays(array $fight): array
    {
        $data = $this->core("/events/{$fight['event_id']}/competitions/{$fight['comp_id']}/plays", ['limit' => 500]);
        $out  = [];
        foreach ($data['items'] ?? [] as $p) {
            $text = (string) ($p['text'] ?? $p['shortText'] ?? $p['alternativeText'] ?? '');
            if ($text === '' && empty($p['type']['text'])) {
                continue;
            }
            $ref = (string) ($p['participants'][0]['athlete']['$ref'] ?? '');
            $out[] = [
                'text'       => $text,
                'type'       => (string) ($p['type']['text'] ?? ''),
                'round'      => isset($p['period']['number']) ? (int) $p['period']['number'] : null,
                'clock'      => $p['clock']['displayValue'] ?? null,
                'at'         => $p['wallclock'] ?? null,
                'athlete_id' => preg_match('#/athletes/(\d+)#', $ref, $m) ? $m[1] : null,
            ];
        }
        return $out;
    }

    /**
     * ESPN MMA news plus each fighter's own feed, newest first.
     * @return array<int, array{title:string, text:string, at:?string, url:?string, author:string, engagement:int}>
     */
    public function news(array $athleteIds = []): array
    {
        // UFC feed (falls back to the all-MMA feed), then each fighter's own feed.
        $feeds = [[$this->siteUrl . '/ufc/news?limit=50', $this->siteUrl . '/news?limit=50']];
        foreach (array_filter(array_unique($athleteIds)) as $id) {
            $feeds[] = [$this->siteUrl . '/ufc/athletes/' . rawurlencode((string) $id) . '/news?limit=25'];
        }
        $out    = [];
        $seen   = [];
        $errors = [];
        $ok     = 0;
        foreach ($feeds as $urls) {
            $data = null;
            foreach ($urls as $url) {
                try {
                    $data = $this->feeds[$url] ??= $this->get($url);
                    break;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    if ($this->blocked !== null) {
                        break 2;
                    }
                }
            }
            if ($data === null) {
                continue;
            }
            $ok++;
            foreach ($data['articles'] ?? $data['feed'] ?? [] as $a) {
                $title = trim((string) ($a['headline'] ?? $a['title'] ?? ''));
                $link  = $a['links']['web']['href'] ?? null;
                $key   = $link ?? $title;
                if ($title === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'title'      => $title,
                    'text'       => $title . '. ' . (string) ($a['description'] ?? ''),
                    'at'         => $a['published'] ?? $a['lastModified'] ?? null,
                    'url'        => $link,
                    'author'     => 'ESPN' . (!empty($a['byline']) ? ' · ' . $a['byline'] : ''),
                    'engagement' => 0,
                ];
            }
        }
        if ($ok === 0 && $errors) {
            throw new HttpException('ESPN news unavailable: ' . $errors[0]);
        }
        usort($out, fn($a, $b) => strtotime((string) $b['at']) <=> strtotime((string) $a['at']));
        return $out;
    }

    /**
     * findFight() plus odds, fight-total stats and round scores (stats/rounds only once the fight has started).
     * This is what gets saved in events.espn_data.
     */
    public function fightDetails(FightContext $ctx, array $days, ?array $previous = null): ?array
    {
        $fight = $this->findFight($ctx, $days);
        if ($fight === null || $fight['event_id'] === '' || $fight['comp_id'] === '') {
            return $fight;
        }
        $same = $previous && ($previous['comp_id'] ?? null) === $fight['comp_id'];

        // Odds barely move: refetch at most hourly.
        if ($same && !empty($previous['odds_at']) && time() - strtotime($previous['odds_at']) < 3600) {
            $fight['odds']    = $previous['odds'] ?? null;
            $fight['odds_at'] = $previous['odds_at'];
        } else {
            $fight['odds']    = $this->odds($fight);
            $fight['odds_at'] = gmdate(DATE_ATOM);
        }

        // Stats and round scores: every refresh while the fight is on; once it's final, keep what we have.
        if ($fight['state'] !== 'pre') {
            if ($same && ($previous['state'] ?? '') === 'post' && !empty($previous['stats'])) {
                $fight['stats']      = $previous['stats'];
                $fight['linescores'] = $previous['linescores'] ?? [];
            } else {
                $fight['stats']      = $this->stats($fight);
                $fight['linescores'] = $this->linescores($fight);
            }
        }
        $fight['url']        = self::fightUrl($fight);
        $fight['checked_at'] = gmdate(DATE_ATOM);
        return $fight;
    }

    /** American moneyline → implied probability (0–1), without removing the vig. */
    public static function impliedProbability(int $moneyline): float
    {
        return $moneyline < 0 ? -$moneyline / (-$moneyline + 100) : 100 / ($moneyline + 100);
    }

    public static function fightUrl(array $fight): string
    {
        return $fight['event_id'] !== ''
            ? 'https://www.espn.com/mma/fightcenter/_/id/' . rawurlencode($fight['event_id']) . '/league/ufc'
            : 'https://www.espn.com/mma/fightcenter';
    }

    private function core(string $path, array $query = []): array
    {
        try {
            return $this->get($this->coreUrl . $path . ($query ? '?' . http_build_query($query) : ''), 8);
        } catch (Throwable) {
            return [];   // optional extras: leave them out
        }
    }

    private static function walk(array $node, callable $fn): void
    {
        $fn($node);
        foreach ($node as $v) {
            if (is_array($v)) {
                self::walk($v, $fn);
            }
        }
    }
}
