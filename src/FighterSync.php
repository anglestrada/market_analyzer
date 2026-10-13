<?php
declare(strict_types=1);

final class SyncBudgetExceeded extends RuntimeException {}

/**
 * Fills fighters / fights / fight_participants / fight_stats from ESPN for every fighter on an open
 * Kalshi market, plus what's needed about their past opponents.
 *
 *  1. linkMarkets()      Kalshi market "Will <fighter> win?" → ESPN athlete (market_fighters)
 *  2. syncFighter(full)  the market's fighters: profile, every fight, method, duration, both fighters' stats
 *  3. syncFighter(basic) their past opponents: profile, and only the fights since they met (date + result),
 *                        enough to work out the opponent's record on the day:
 *                        record then = current record − results from that fight onward.
 *
 * Past fights never change, so anything already stored is skipped; a run that hits its request budget
 * stops cleanly and the next run carries on.
 */
final class FighterSync
{
    private const FULL_RESYNC_HOURS = 12;   // re-read a card fighter's eventlog this often (new fights)
    private const FINISHED_RESYNC_HOURS = 1; // … but hourly once their fight is over, until ESPN has its result + stats
    private const RECENT_FIGHT_DAYS = 14;   // keep trying that for fights up to this many days old

    private int $requests = 0;
    private int $budget   = 0;
    private array $cache  = [];
    private int $fighters = 0;
    private int $fights   = 0;
    private bool $deep    = false;

    /** @var callable|null */
    private $log;
    /** @var callable|null fn(string $line, bool $transient): progress for the CLI (the scan doesn't set it) */
    private $progress = null;
    private ?string $label = null;   // "Fighter 2/4", "Fighter 2/4 > opponent 5/24"
    private float $started = 0.0;

    public function __construct(
        private PDO $db,
        private EspnClient $espn,
        ?callable $log = null,
        private string $core = 'https://sports.core.api.espn.com/v2/sports/mma',
        private string $search = 'https://site.web.api.espn.com/apis/search/v2',
    ) {
        $this->log = $log;
    }

    public static function make(?callable $log = null): self
    {
        return new self(db(), new EspnClient(), $log);
    }

    /**
     * Progress lines while it runs. $transient = true for the "fight 12/27 | 140 requests" line that the CLI
     * overwrites in place; false for lines worth keeping ("Fighter 2/4 Alexander Volkanovski: 31 fights on ESPN, 27 to fetch").
     */
    public function onProgress(callable $cb): self
    {
        $this->progress = $cb;
        return $this;
    }

    private function progress(string $line, bool $transient = true): void
    {
        if ($this->progress === null) {
            return;
        }
        $sec = (int) (microtime(true) - $this->started);
        $tail = sprintf('%d/%d requests | %d:%02d', $this->requests, $this->budget, intdiv($sec, 60), $sec % 60);
        ($this->progress)($transient ? "$line | $tail" : $line, $transient);
    }

    /**
     * @param int[]|null $marketIds only these markets (default: every open UFC market)
     * @return array{run_id:int, status:string, requests:int, fighters:int, fights:int, error:?string}
     */
    /**
     * @param bool $deep also sync every past opponent's full history and stats (for the history backtest);
     *                   several times more ESPN requests the first time, then only new fights
     */
    public function run(int $budget = 1500, ?array $marketIds = null, bool $relink = false, bool $deep = false): array
    {
        $this->budget = max(1, $budget);
        $this->deep   = $deep;
        $this->started = microtime(true);
        $runId = (int) $this->db->query("INSERT INTO fighter_sync_runs (status) VALUES ('running') RETURNING id")->fetchColumn();
        $status = 'completed';
        $error  = null;

        try {
            $this->linkMarkets($marketIds, $relink);

            // Fighters on the selected markets, both sides.
            $rows = $this->marketRows($marketIds);
            $ids  = [];   // fighter id => fight just finished
            foreach ($rows as $r) {
                foreach ([$r['fighter_id'], $r['opponent_id']] as $id) {
                    if ($id) {
                        $ids[(int) $id] = ($ids[(int) $id] ?? false) || (bool) $r['finished'];
                    }
                }
            }
            $this->progress('Step 2 of 3 | card fighters: ' . count($ids) . ' to check', false);
            $i = 0;
            foreach ($ids as $fighterId => $finished) {
                $this->label = sprintf('Fighter %d/%d', ++$i, count($ids));
                // A finished fight asked for by --market= is re-read right away; the scan does it at most hourly.
                $this->syncFighter($fighterId, 'full', null, $finished ? ($marketIds ? 0 : self::FINISHED_RESYNC_HOURS) : null);
            }
            // Their past opponents: just enough history for the record on the day they met.
            $this->progress('Step 3 of 3 | past opponents of the card fighters (their record on the day they fought)', false);
            $i = 0;
            foreach (array_keys($ids) as $fighterId) {
                $this->label = sprintf('Fighter %d/%d', ++$i, count($ids));
                $this->syncOpponentRecords($fighterId);
            }
            $this->label = null;
        } catch (SyncBudgetExceeded) {
            $status = 'partial';
            $error  = "Stopped after {$this->requests} ESPN requests (budget); the next run continues.";
        } catch (Throwable $e) {
            $status = $this->fights > 0 ? 'partial' : 'failed';
            $error  = $e->getMessage();
            error_log('[fighter-sync] ' . $e);
        }

        $this->db->prepare(
            'UPDATE fighter_sync_runs SET finished_at = NOW(), status = :s, fighters_synced = :f, fights_synced = :n,
                    espn_requests = :r, error_message = :e WHERE id = :id'
        )->execute([':s' => $status, ':f' => $this->fighters, ':n' => $this->fights, ':r' => $this->requests,
                    ':e' => $error, ':id' => $runId]);
        $this->say(sprintf('Fighter sync #%d %s: %d fighters, %d fights, %d ESPN requests%s',
            $runId, $status, $this->fighters, $this->fights, $this->requests, $error ? " ($error)" : ''));

        return ['run_id' => $runId, 'status' => $status, 'requests' => $this->requests,
                'fighters' => $this->fighters, 'fights' => $this->fights, 'error' => $error];
    }

    /* ------------------------------------------------------------------ 1. market → ESPN athlete */

    public function linkMarkets(?array $marketIds = null, bool $relink = false): void
    {
        $sql = "SELECT m.id, m.event_id, m.yes_subtitle, m.no_subtitle, m.market_title,
                       e.event_title, e.espn_data, mf.market_id AS linked, mf.match_method, mf.opponent_id
                  FROM markets m
                  JOIN events e ON e.id = m.event_id
             LEFT JOIN market_fighters mf ON mf.market_id = m.id
                 WHERE e.sport = :sport";
        $params = [':sport' => DEFAULT_SPORT];
        if ($marketIds) {
            $sql .= ' AND m.id IN (' . implode(',', array_map('intval', $marketIds)) . ')';
        } else {
            $sql .= " AND m.status = 'open'";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $all  = $stmt->fetchAll();
        $todo = array_values(array_filter($all, fn($m) => !($m['match_method'] === 'manual' || ($m['linked'] && $m['opponent_id'] && !$relink))));
        $this->progress(sprintf('Step 1 of 3 | matching markets to ESPN: %d of %d need it', count($todo), count($all)), false);
        foreach ($todo as $n => $m) {
            $this->progress(sprintf('Matching market %d/%d: %s', $n + 1, count($todo), $m['yes_subtitle'] ?: $m['market_title']));
            $name = trim((string) $m['yes_subtitle']);
            if ($name === '' || in_array(strtolower($name), ['yes', 'no'], true)) {
                continue;
            }
            $ctx      = FightContext::forMovement($this->db, $m + ['dropped_side' => 'yes']);
            $oppName  = $ctx->opponents[0] ?? (trim((string) $m['no_subtitle']) ?: null);
            $fighter  = null;
            $opponent = null;
            $fightId  = null;

            // Best source: the ESPN bout the scanner already found for this event (events.espn_data).
            $espn = $m['espn_data'] ? json_decode((string) $m['espn_data'], true) : null;
            if (is_array($espn) && !empty($espn['fighters'])) {
                foreach ($espn['fighters'] as $f) {
                    $aid = (string) ($f['athlete_id'] ?? '');
                    if ($aid === '') {
                        continue;
                    }
                    if ($ctx->whichFighter((string) $f['name']) === $ctx->subject) {
                        $fighter = $this->ensureFighter($aid, (string) $f['name']);
                    } else {
                        $opponent = $this->ensureFighter($aid, (string) $f['name']);
                    }
                }
                if (!empty($espn['comp_id']) && !empty($espn['event_id'])) {
                    $fightId = $this->upsertUpcomingFight($espn);
                }
            }
            // Otherwise ESPN's search, by name.
            $fighter  ??= $this->searchFighter($name);
            $opponent ??= $oppName ? $this->searchFighter($oppName) : null;
            if ($fighter === null) {
                $this->say("  ? no ESPN match for \"$name\" (market #{$m['id']})");
                continue;
            }

            $this->db->prepare(
                "INSERT INTO market_fighters (market_id, fighter_id, opponent_id, upcoming_fight_id, match_method)
                 VALUES (:m, :f, :o, :u, 'auto')
                 ON CONFLICT (market_id) DO UPDATE
                    SET fighter_id = EXCLUDED.fighter_id, opponent_id = EXCLUDED.opponent_id,
                        upcoming_fight_id = COALESCE(EXCLUDED.upcoming_fight_id, market_fighters.upcoming_fight_id),
                        matched_at = NOW()
                  WHERE market_fighters.match_method = 'auto'"
            )->execute([':m' => $m['id'], ':f' => $fighter, ':o' => $opponent, ':u' => $fightId]);
        }
    }

    /**
     * ESPN fighter for a Kalshi name. Kalshi and ESPN don't always spell names the same ("Alex Volkanovski" on
     * Kalshi is "Alexander Volkanovski" on ESPN, whose search then finds nothing), so: the full name first, then
     * the last name alone, keeping only a result whose last name matches and whose first name fits
     * (Alex / Alexander, same initial, or the names in the other order).
     */
    private function searchFighter(string $name): ?int
    {
        $tokens = self::nameTokens($name);
        if (!$tokens) {
            return null;
        }
        $last    = (string) end($tokens);
        $queries = [$name];
        if (count($tokens) > 1 && strlen($last) >= 3) {
            $queries[] = $last;
        }
        foreach ($queries as $q) {
            $best = null;
            $bestScore = 0;
            foreach ($this->searchCandidates($q) as [$aid, $display]) {
                $score = self::nameScore($tokens, self::nameTokens($display));
                if ($score > $bestScore) {
                    [$best, $bestScore] = [[$aid, $display], $score];
                }
            }
            if ($best !== null) {
                if ($q !== $name || strcasecmp($best[1], $name) !== 0) {
                    $this->say("  ~ \"$name\" matched ESPN \"{$best[1]}\" (#{$best[0]})");
                }
                return $this->ensureFighter($best[0], $best[1]);
            }
        }
        return null;
    }

    /** @return list<array{0:string,1:string}> ESPN athlete id + display name for MMA players in a search */
    private function searchCandidates(string $query): array
    {
        $data = $this->get($this->search . '?' . http_build_query(['query' => $query, 'limit' => 10]), true);
        $out = [];
        foreach ($data['results'] ?? [] as $group) {
            if (($group['type'] ?? '') !== 'player') {
                continue;
            }
            foreach ($group['contents'] ?? [] as $c) {
                $web = (string) ($c['link']['web'] ?? '');
                if (($c['sport'] ?? '') === 'mma' && preg_match('#/mma/fighter/_/id/(\d+)#', $web, $mm)) {
                    $out[] = [$mm[1], (string) ($c['displayName'] ?? $query)];
                }
            }
        }
        return $out;
    }

    /** "José Aldo Jr." → ['jose', 'aldo'] (lower case, no accents, punctuation or Jr./Sr.) */
    public static function nameTokens(string $name): array
    {
        $s = trim($name);
        if (function_exists('transliterator_transliterate')) {
            $s = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        } elseif (function_exists('iconv')) {
            $s = (string) (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
        }
        $s = strtolower(preg_replace("/[^A-Za-z\s-]+/", '', $s));
        $parts = preg_split('/[\s-]+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        while (count($parts) > 1 && in_array(end($parts), ['jr', 'sr', 'ii', 'iii', 'iv'], true)) {
            array_pop($parts);
        }
        return $parts;
    }

    /**
     * How well two names fit, 0 = not the same fighter. Last names must match (or the two names are the same
     * words in another order, e.g. "Song Yadong" / "Yadong Song"); then the first name: same > one starts with
     * the other (Alex / Alexander) > same initial > no first name to compare.
     */
    public static function nameScore(array $a, array $b): int
    {
        if (!$a || !$b) {
            return 0;
        }
        $sa = $a; $sb = $b;
        sort($sa); sort($sb);
        if ($sa === $sb) {
            return 100;
        }
        [$la, $lb] = [(string) end($a), (string) end($b)];
        if ($la !== $lb && !(strlen($la) >= 6 && levenshtein($la, $lb) <= 1)) {   // one-letter spelling slips only
            return 0;
        }
        if (count($a) === 1 || count($b) === 1) {
            return 10;
        }
        [$fa, $fb] = [$a[0], $b[0]];
        return match (true) {
            $fa === $fb                                    => 90,
            str_starts_with($fa, $fb) || str_starts_with($fb, $fa) => 70,
            $fa[0] === $fb[0]                              => 40,
            default                                        => 0,
        };
    }

    private function upsertUpcomingFight(array $espn): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO fights (espn_competition_id, espn_event_id, event_name, league, fight_date, weight_class,
                                 is_title, title_type, scheduled_rounds, status_state)
             VALUES (:c, :e, :n, 'ufc', :d, :w, CAST(:t AS BOOLEAN), :tt, :r, :s)
             ON CONFLICT (espn_competition_id) DO UPDATE
                SET fight_date = COALESCE(EXCLUDED.fight_date, fights.fight_date),
                    weight_class = COALESCE(EXCLUDED.weight_class, fights.weight_class),
                    status_state = CASE WHEN fights.status_state = 'post' THEN 'post' ELSE EXCLUDED.status_state END
             RETURNING id"
        );
        [$isTitle, $titleType] = self::title((string) ($espn['weight_class'] ?? ''));
        $stmt->execute([
            ':c' => (string) $espn['comp_id'], ':e' => (string) $espn['event_id'], ':n' => $espn['event'] ?? null,
            ':d' => $espn['start'] ?? null, ':w' => $espn['weight_class'] ?? null,
            ':t' => $isTitle ? 1 : 0, ':tt' => $titleType,
            ':r' => isset($espn['rounds']) ? (int) $espn['rounds'] : null,
            ':s' => in_array($espn['state'] ?? '', ['pre', 'in', 'post'], true) ? $espn['state'] : 'pre',
        ]);
        return (int) $stmt->fetchColumn() ?: null;
    }

    /* ------------------------------------------------------------------ 2/3. fighter history */

    /**
     * @param string $level 'full'  = every fight with method, duration and both fighters' stats
     *                      'basic' = profile + fights back to $since (date + result only)
     */
    public function syncFighter(int $fighterId, string $level = 'full', ?string $since = null, ?int $freshHours = null): void
    {
        $f = $this->fighter($fighterId);
        if (!$f) {
            return;
        }
        $freshHours ??= self::FULL_RESYNC_HOURS;
        $fresh = $f['history_synced_at'] && strtotime($f['history_synced_at']) > time() - $freshHours * 3600;
        $who = trim(($this->label ? $this->label . ' ' : '') . $f['full_name']);
        if ($level === 'full' && $fresh) {
            $this->progress("$who: up to date");
            return;
        }

        $this->progress("$who: reading profile");
        $this->syncProfile($f);
        $who = trim(($this->label ? $this->label . ' ' : '') . ($this->fighter($fighterId)['full_name'] ?? $f['full_name']));   // ESPN's spelling now
        $this->progress("$who: reading fight list");
        $log   = $this->get($this->core . "/athletes/{$f['espn_athlete_id']}/eventlog?limit=200");
        $items = $log['events']['items'] ?? $log['items'] ?? [];
        $this->db->prepare('UPDATE fighters SET fights_listed = :n WHERE id = :id')->execute([':n' => count($items), ':id' => $fighterId]);

        // How many of the listed fights still need fetching (for "fight 3/12"; the basic level may stop early).
        $need = 0;
        foreach ($items as $item) {
            $known = preg_match('#/competitions/(\d+)#', (string) ($item['competition']['$ref'] ?? ''), $cm) ? $this->knownFight($cm[1]) : null;
            if (!($known && ($known['detail_level'] === 'full' || $level === 'basic') && $known['status_state'] === 'post')) {
                $need++;
            }
        }
        if ($level === 'full') {
            $this->progress(sprintf('%s: %d fights on ESPN, %s', $who, count($items), $need ? "$need to fetch" : 'all stored already'), $need === 0);
        }
        $done = 0;

        $sinceTs = $since ? strtotime($since) : null;
        foreach ($items as $item) {
            $compUrl = (string) ($item['competition']['$ref'] ?? '');
            if (!preg_match('#/events/(\d+)/competitions/(\d+)#', $compUrl, $mm)) {
                continue;
            }
            [, $eventId, $compId] = $mm;
            $league = preg_match('#/leagues/([a-z0-9-]+)/#', $compUrl, $lm) ? $lm[1] : 'ufc';

            $known = $this->knownFight($compId);
            if ($known && ($known['detail_level'] === 'full' || $level === 'basic') && $known['status_state'] === 'post') {
                if ($sinceTs !== null && $known['fight_date'] && strtotime($known['fight_date']) < $sinceTs) {
                    break;   // eventlog is newest first: everything older is already covered
                }
                continue;
            }
            $this->progress(sprintf('%s: fight %d/%d', $who, ++$done, $need));
            $date = $this->syncFight($compUrl, $eventId, $compId, $league, $level);
            if ($sinceTs !== null && $date !== null && strtotime($date) < $sinceTs) {
                break;
            }
        }

        $this->computeRecordsBefore($fighterId);
        if ($level === 'full') {
            $this->db->prepare('UPDATE fighters SET history_synced_at = NOW() WHERE id = :id')->execute([':id' => $fighterId]);
        }
        $this->fighters++;
    }

    /** For each past opponent of this fighter whose record on the day is unknown, sync their fights since then. */
    private function syncOpponentRecords(int $fighterId): void
    {
        if ($this->deep) {
            // Every UFC opponent at full detail (their whole history + stats), not just their record on the day.
            $stmt = $this->db->prepare(
                "SELECT DISTINCT o.fighter_id FROM fight_participants me
                   JOIN fight_participants o ON o.fight_id = me.fight_id AND o.fighter_id <> me.fighter_id
                   JOIN fights f ON f.id = me.fight_id
                  WHERE me.fighter_id = :id AND f.status_state = 'post' AND f.league = 'ufc'"
            );
            $stmt->execute([':id' => $fighterId]);
            $opps = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $this->opponentsStart($fighterId, count($opps));
            foreach ($opps as $j => $opp) {
                $this->label = $this->opponentLabel($fighterId, $j, count($opps));
                $this->syncFighter((int) $opp, 'full');
            }
            return;
        }
        $stmt = $this->db->prepare(
            "SELECT o.fighter_id AS opp, MIN(f.fight_date) AS since
               FROM fight_participants me
               JOIN fight_participants o ON o.fight_id = me.fight_id AND o.fighter_id <> me.fighter_id
               JOIN fights f ON f.id = me.fight_id
              WHERE me.fighter_id = :id AND f.status_state = 'post' AND o.wins_before IS NULL
              GROUP BY o.fighter_id"
        );
        $stmt->execute([':id' => $fighterId]);
        $opps = $stmt->fetchAll();
        $this->opponentsStart($fighterId, count($opps));
        foreach ($opps as $j => $r) {
            $this->label = $this->opponentLabel($fighterId, $j, count($opps));
            $this->syncFighter((int) $r['opp'], 'basic', $r['since']);
        }
    }

    private function opponentsStart(int $fighterId, int $n): void
    {
        $name = $this->fighter($fighterId)['full_name'] ?? "#$fighterId";
        $this->progress(sprintf('%s %s: %s', $this->label, $name, $n ? "$n past opponents to fill in" : 'opponents done already'), $n === 0);
    }

    private function opponentLabel(int $fighterId, int $j, int $n): string
    {
        return sprintf('%s > opponent %d/%d', preg_replace('/ > opponent.*$/', '', (string) $this->label), $j + 1, $n);
    }

    private function syncProfile(array $f): void
    {
        $p = $this->get($this->core . "/athletes/{$f['espn_athlete_id']}");
        if (!$p) {
            return;
        }
        [$w, $l, $d, $nc] = $this->currentRecord($p);
        $this->db->prepare(
            'UPDATE fighters SET full_name = :name, nickname = :nick, date_of_birth = :dob, height_in = :h, reach_in = :r,
                    weight_lbs = :wt, stance = :st, association = :a, citizenship = :c, weight_class = :wc,
                    styles = :styles, ranks = :ranks, wins = COALESCE(:w, wins), losses = COALESCE(:l, losses),
                    draws = COALESCE(:d, draws), no_contests = COALESCE(:nc, no_contests),
                    raw_profile = :raw, profile_synced_at = NOW()
              WHERE id = :id'
        )->execute([
            ':name'   => $p['displayName'] ?? $p['fullName'] ?? $f['full_name'],
            ':nick'   => $p['nickname'] ?? null,
            ':dob'    => !empty($p['dateOfBirth']) ? substr((string) $p['dateOfBirth'], 0, 10) : null,
            ':h'      => self::positive($p['height'] ?? null),
            ':r'      => self::positive($p['reach'] ?? null),
            ':wt'     => self::positive($p['weight'] ?? null),
            ':st'     => self::text(is_array($p['stance'] ?? null) ? ($p['stance']['text'] ?? null) : ($p['stance'] ?? null)),
            ':a'      => self::text(is_array($p['association'] ?? null) ? ($p['association']['name'] ?? null) : ($p['association'] ?? null)),
            ':c'      => self::text($p['citizenship'] ?? null),
            ':wc'     => is_array($p['weightClass'] ?? null) ? ($p['weightClass']['text'] ?? null) : ($p['weightClass'] ?? null),
            ':styles' => isset($p['styles']) ? self::json($p['styles']) : null,
            ':ranks'  => isset($p['ranks']) ? self::json($p['ranks']) : null,
            ':w' => $w, ':l' => $l, ':d' => $d, ':nc' => $nc,
            ':raw'    => self::json(array_diff_key($p, ['links' => 1, 'images' => 1, 'headshot' => 1, 'flag' => 1])),
            ':id'     => $f['id'],
        ]);
    }

    /** @return array{0:?int,1:?int,2:?int,3:?int} current W, L, D, NC from the profile's "records" link */
    private function currentRecord(array $profile): array
    {
        $node  = $this->ref($profile['records'] ?? $profile['record'] ?? null);
        $items = $node['items'] ?? (isset($node['summary']) ? [$node] : []);
        $best  = null;
        foreach ($items as $it) {
            $it = $this->ref($it) ?? [];
            if (!isset($it['summary'])) {
                continue;
            }
            $best ??= $it;
            if (in_array(strtolower((string) ($it['type'] ?? $it['name'] ?? '')), ['total', 'overall'], true)) {
                $best = $it;
                break;
            }
        }
        return $best ? self::parseRecord((string) $best['summary']) : [null, null, null, null];
    }

    /** "25-8-1" or "25-8-1 (1 NC)" → [25, 8, 1, 1] */
    public static function parseRecord(string $s): array
    {
        if (!preg_match('/(\d+)\s*-\s*(\d+)(?:\s*-\s*(\d+))?/', $s, $m)) {
            return [null, null, null, null];
        }
        $nc = preg_match('/(\d+)\s*NC/i', $s, $n) ? (int) $n[1] : 0;
        return [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0), $nc];
    }

    /** One competition → fights + fight_participants (+ fight_stats when $level is full). @return ?string fight date */
    private function syncFight(string $compUrl, string $eventId, string $compId, string $league, string $level): ?string
    {
        $comp = $this->get($compUrl);
        if (!$comp) {
            return null;
        }
        $date   = $comp['date'] ?? $comp['startDate'] ?? null;
        $sides  = [];
        foreach ($comp['competitors'] ?? [] as $c) {
            $aid = (string) ($c['id'] ?? '');
            if ($aid === '' && preg_match('#/athletes/(\d+)#', (string) ($c['athlete']['$ref'] ?? ''), $am)) {
                $aid = $am[1];
            }
            if ($aid === '') {
                continue;
            }
            $sides[] = ['aid' => $aid, 'node' => $c, 'winner' => $c['winner'] ?? null, 'order' => $c['order'] ?? null];
        }
        if (count($sides) !== 2) {
            return $date;
        }

        // Status (method, round, clock) is needed for full detail, or when nobody won (draw / NC / not fought).
        $status = null;
        if ($level === 'full' || ($sides[0]['winner'] !== true && $sides[1]['winner'] !== true)) {
            $status = $this->ref($comp['status'] ?? null) ?? [];
        }
        $state  = (string) ($status['type']['state'] ?? 'post');
        $state  = in_array($state, ['pre', 'in', 'post'], true) ? $state : 'post';
        $resultName = strtolower((string) ($status['result']['name'] ?? $status['result']['displayName'] ?? ''));

        $rounds = preg_match('/(\d)\s*Rnd/i', (string) ($comp['description'] ?? ''), $rm) ? (int) $rm[1] : null;
        $period = isset($status['period']) ? (int) $status['period'] : null;
        $clock  = isset($status['clock']) && is_numeric($status['clock']) ? (int) round((float) $status['clock']) : null;
        $clock  = $clock !== null ? max(0, min(300, $clock)) : null;
        $duration = null;
        if ($period !== null && $period > 0) {
            $duration = ($period - 1) * 300 + ($clock ?? 300);
        } elseif (str_contains($resultName, 'dec') && $rounds) {
            $duration = $rounds * 300;
        }
        $weight = $comp['type']['text'] ?? $comp['type']['abbreviation'] ?? null;
        [$isTitle, $titleType] = self::title((string) $weight);

        $stmt = $this->db->prepare(
            "INSERT INTO fights (espn_competition_id, espn_event_id, league, fight_date, weight_class, is_title, title_type,
                                 scheduled_rounds, end_round, end_clock_seconds, duration_seconds, method, method_detail,
                                 status_state, raw_competition, raw_status, detail_level)
             VALUES (:c, :e, :lg, :d, :w, CAST(:t AS BOOLEAN), :tt, :r, :er, :ec, :dur, :m, :md, :s, :rc, :rs, :lvl)
             ON CONFLICT (espn_competition_id) DO UPDATE
                SET fight_date = EXCLUDED.fight_date, weight_class = EXCLUDED.weight_class, is_title = EXCLUDED.is_title,
                    title_type = EXCLUDED.title_type, league = EXCLUDED.league,
                    scheduled_rounds = COALESCE(EXCLUDED.scheduled_rounds, fights.scheduled_rounds),
                    end_round = COALESCE(EXCLUDED.end_round, fights.end_round),
                    end_clock_seconds = COALESCE(EXCLUDED.end_clock_seconds, fights.end_clock_seconds),
                    duration_seconds = COALESCE(EXCLUDED.duration_seconds, fights.duration_seconds),
                    method = COALESCE(EXCLUDED.method, fights.method),
                    method_detail = COALESCE(EXCLUDED.method_detail, fights.method_detail),
                    status_state = EXCLUDED.status_state,
                    raw_status = COALESCE(EXCLUDED.raw_status, fights.raw_status),
                    detail_level = CASE WHEN fights.detail_level = 'full' THEN 'full' ELSE EXCLUDED.detail_level END
             RETURNING id"
        );
        $stmt->execute([
            ':c' => $compId, ':e' => $eventId, ':lg' => $league, ':d' => $date, ':w' => $weight,
            ':t' => $isTitle ? 1 : 0, ':tt' => $titleType, ':r' => $rounds,
            ':er' => $period, ':ec' => $clock, ':dur' => $duration,
            ':m'  => $status['result']['displayName'] ?? $status['result']['shortDisplayName'] ?? null,
            ':md' => $status['result']['displayDescription'] ?? $status['result']['description'] ?? null,
            ':s'  => $state,
            ':rc' => self::json(array_diff_key($comp, ['competitors' => 1, 'venue' => 1])),
            ':rs' => $status ? self::json($status) : null,
            ':lvl' => $level === 'full' ? 'full' : 'basic',
        ]);
        $fightId = (int) $stmt->fetchColumn();
        $this->fights++;

        // Participants
        $ids = [];
        foreach ($sides as $s) {
            $ids[$s['aid']] = $this->ensureFighter($s['aid'], null);
        }
        foreach ($sides as $i => $s) {
            $other  = $sides[1 - $i];
            $result = null;
            if ($state === 'post') {
                $result = match (true) {
                    $s['winner'] === true                                         => 'W',
                    $other['winner'] === true                                     => 'L',
                    str_contains($resultName, 'draw')                             => 'D',
                    str_contains($resultName, 'nc') || str_contains($resultName, 'no contest') => 'NC',
                    default                                                       => null,
                };
            }
            $this->db->prepare(
                'INSERT INTO fight_participants (fight_id, fighter_id, opponent_id, espn_competitor_id, corner_order, result)
                 VALUES (:f, :me, :o, :cid, :ord, :res)
                 ON CONFLICT (fight_id, fighter_id) DO UPDATE
                    SET opponent_id = EXCLUDED.opponent_id, result = COALESCE(EXCLUDED.result, fight_participants.result)'
            )->execute([':f' => $fightId, ':me' => $ids[$s['aid']], ':o' => $ids[$other['aid']],
                        ':cid' => $s['aid'], ':ord' => $s['order'], ':res' => $result]);

            if ($level === 'full' && $state === 'post') {
                $this->syncStats($fightId, $ids[$s['aid']], $s['node']);
            }
        }
        return $date;
    }

    private function syncStats(int $fightId, int $fighterId, array $competitor): void
    {
        $stats = $this->ref($competitor['statistics'] ?? null);
        if (!$stats) {
            return;   // usually a non-UFC fight
        }
        $v = [];
        $walk = function (array $node) use (&$walk, &$v): void {
            if (isset($node['name']) && array_key_exists('value', $node) && !is_array($node['value'])) {
                $v[(string) $node['name']] = $node;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($stats);
        if (!$v) {
            return;
        }
        $n   = fn(string $k) => isset($v[$k]) && is_numeric($v[$k]['value']) ? (int) round((float) $v[$k]['value']) : 0;
        $sum = fn(string ...$keys) => array_sum(array_map($n, $keys));
        $ctrl = 0;
        if (isset($v['timeInControl'])) {
            $dv = (string) ($v['timeInControl']['displayValue'] ?? '');
            $ctrl = preg_match('/^(\d+):(\d{2})$/', $dv, $cm) ? (int) $cm[1] * 60 + (int) $cm[2] : $n('timeInControl');
        }
        $pos = fn(string $p, string $t) => $sum("sig{$p}HeadStrikes$t", "sig{$p}BodyStrikes$t", "sig{$p}LegStrikes$t");
        $tgt = fn(string $t) => $sum("sigDistance{$t}StrikesLanded", "sigClinch{$t}StrikesLanded", "sigGround{$t}StrikesLanded");

        $row = [
            ':kd' => $n('knockDowns'), ':tl' => $n('totalStrikesLanded'), ':ta' => $n('totalStrikesAttempted'),
            ':sl' => $n('sigStrikesLanded'), ':sa' => $n('sigStrikesAttempted'),
            ':dl' => $pos('Distance', 'Landed'), ':da' => $pos('Distance', 'Attempted'),
            ':cl' => $pos('Clinch', 'Landed'), ':ca' => $pos('Clinch', 'Attempted'),
            ':gl' => $pos('Ground', 'Landed'), ':ga' => $pos('Ground', 'Attempted'),
            ':hl' => $tgt('Head'), ':bl' => $tgt('Body'), ':ll' => $tgt('Leg'),
            ':tdl' => $n('takedownsLanded'), ':tda' => $n('takedownsAttempted'),
            ':sub' => $n('submissions'), ':rev' => $n('reversals'), ':ctrl' => $ctrl,
        ];
        // Keep the CHECKs happy if ESPN ever reports landed > attempted.
        $row[':sa']  = max($row[':sa'], $row[':sl']);
        $row[':tda'] = max($row[':tda'], $row[':tdl']);

        $this->db->prepare(
            'INSERT INTO fight_stats (fight_id, fighter_id, knockdowns, total_strikes_landed, total_strikes_attempted,
                    sig_strikes_landed, sig_strikes_attempted, sig_distance_landed, sig_distance_attempted,
                    sig_clinch_landed, sig_clinch_attempted, sig_ground_landed, sig_ground_attempted,
                    sig_head_landed, sig_body_landed, sig_leg_landed, takedowns_landed, takedowns_attempted,
                    submission_attempts, reversals, control_seconds, raw_stats)
             VALUES (:f, :me, :kd, :tl, :ta, :sl, :sa, :dl, :da, :cl, :ca, :gl, :ga, :hl, :bl, :ll, :tdl, :tda, :sub, :rev, :ctrl, :raw)
             ON CONFLICT (fight_id, fighter_id) DO UPDATE
                SET knockdowns = EXCLUDED.knockdowns, total_strikes_landed = EXCLUDED.total_strikes_landed,
                    total_strikes_attempted = EXCLUDED.total_strikes_attempted, sig_strikes_landed = EXCLUDED.sig_strikes_landed,
                    sig_strikes_attempted = EXCLUDED.sig_strikes_attempted, sig_distance_landed = EXCLUDED.sig_distance_landed,
                    sig_distance_attempted = EXCLUDED.sig_distance_attempted, sig_clinch_landed = EXCLUDED.sig_clinch_landed,
                    sig_clinch_attempted = EXCLUDED.sig_clinch_attempted, sig_ground_landed = EXCLUDED.sig_ground_landed,
                    sig_ground_attempted = EXCLUDED.sig_ground_attempted, sig_head_landed = EXCLUDED.sig_head_landed,
                    sig_body_landed = EXCLUDED.sig_body_landed, sig_leg_landed = EXCLUDED.sig_leg_landed,
                    takedowns_landed = EXCLUDED.takedowns_landed, takedowns_attempted = EXCLUDED.takedowns_attempted,
                    submission_attempts = EXCLUDED.submission_attempts, reversals = EXCLUDED.reversals,
                    control_seconds = EXCLUDED.control_seconds, raw_stats = EXCLUDED.raw_stats, synced_at = NOW()'
        )->execute([':f' => $fightId, ':me' => $fighterId, ':raw' => self::json(array_map(fn($x) => $x['displayValue'] ?? $x['value'], $v))] + $row);
    }

    /**
     * wins/losses/draws before each fight = current record − results of that fight and every later one.
     * Needs this fighter's fights to be complete from the newest down to the fight; stops at the first gap.
     */
    public function computeRecordsBefore(int $fighterId): void
    {
        $f = $this->fighter($fighterId);
        if (!$f || $f['profile_synced_at'] === null) {
            return;
        }
        $stmt = $this->db->prepare(
            "SELECT fp.id, fp.result FROM fight_participants fp JOIN fights fi ON fi.id = fp.fight_id
              WHERE fp.fighter_id = :id AND fi.status_state = 'post' AND fi.fight_date IS NOT NULL
              ORDER BY fi.fight_date DESC, fi.id DESC"
        );
        $stmt->execute([':id' => $fighterId]);
        $w = (int) $f['wins']; $l = (int) $f['losses']; $d = (int) $f['draws'];
        $upd = $this->db->prepare("UPDATE fight_participants SET wins_before = :w, losses_before = :l, draws_before = :d,
                                          record_source = 'subtracted' WHERE id = :id");
        foreach ($stmt->fetchAll() as $r) {
            match ($r['result']) {
                'W'  => $w--,
                'L'  => $l--,
                'D'  => $d--,
                'NC' => null,
                default => $w = -1,   // unknown result → can't go further back
            };
            if ($w < 0 || $l < 0 || $d < 0) {
                break;
            }
            $upd->execute([':w' => $w, ':l' => $l, ':d' => $d, ':id' => $r['id']]);
        }
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Fighters to sync: open markets, plus markets whose fight took place in the last RECENT_FIGHT_DAYS days but
     * isn't stored with its result and stats yet (so the accuracy page gets the method, round and strikes).
     * 'finished' = that fight is over, so the fighters are re-read sooner than FULL_RESYNC_HOURS.
     */
    private function marketRows(?array $marketIds): array
    {
        $sql = "SELECT mf.fighter_id, mf.opponent_id,
                       CASE WHEN uf.fight_date IS NOT NULL AND uf.fight_date < :now
                             AND NOT (uf.status_state = 'post' AND uf.detail_level = 'full') THEN 1 ELSE 0 END AS finished
                  FROM market_fighters mf
                  JOIN markets m ON m.id = mf.market_id
             LEFT JOIN fights uf ON uf.id = mf.upcoming_fight_id";
        $params = [':now' => gmdate('Y-m-d H:i:sP')];
        if ($marketIds) {
            $sql .= ' WHERE m.id IN (' . implode(',', array_map('intval', $marketIds)) . ')';
        } else {
            $sql .= " WHERE m.status = 'open'
                         OR (uf.fight_date >= :since AND uf.fight_date < :now2
                             AND NOT (uf.status_state = 'post' AND uf.detail_level = 'full'))";
            $params[':since'] = gmdate('Y-m-d H:i:sP', time() - self::RECENT_FIGHT_DAYS * 86400);
            $params[':now2']  = $params[':now'];
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function fighter(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM fighters WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    private function knownFight(string $compId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, fight_date, status_state, detail_level FROM fights WHERE espn_competition_id = :c');
        $stmt->execute([':c' => $compId]);
        return $stmt->fetch() ?: null;
    }

    private function ensureFighter(string $athleteId, ?string $name): int
    {
        $this->db->prepare(
            'INSERT INTO fighters (espn_athlete_id, full_name) VALUES (:a, :n) ON CONFLICT (espn_athlete_id) DO NOTHING'
        )->execute([':a' => $athleteId, ':n' => $name ?: "ESPN athlete $athleteId"]);
        if ($name) {   // replace the placeholder name once a real one is known
            $this->db->prepare("UPDATE fighters SET full_name = :n WHERE espn_athlete_id = :a AND full_name LIKE 'ESPN athlete %'")
                ->execute([':a' => $athleteId, ':n' => $name]);
        }
        $stmt = $this->db->prepare('SELECT id FROM fighters WHERE espn_athlete_id = :a');
        $stmt->execute([':a' => $athleteId]);
        return (int) $stmt->fetchColumn();
    }

    /** Follows an ESPN {"$ref": …} link; returns the node as-is if it's already expanded. */
    private function ref(mixed $node): ?array
    {
        if (!is_array($node)) {
            return null;
        }
        if (isset($node['$ref']) && count($node) <= 2) {
            return $this->get((string) $node['$ref'], true);
        }
        return $node;
    }

    private function get(string $url, bool $quiet = false): ?array
    {
        $url = preg_replace('#^http://([a-z0-9.-]*espn\.com)#i', 'https://$1', $url);
        if (array_key_exists($url, $this->cache)) {
            return $this->cache[$url];
        }
        if ($this->requests >= $this->budget) {
            throw new SyncBudgetExceeded();
        }
        $this->requests++;
        usleep(150_000);   // polite: under ~7 requests a second
        try {
            return $this->cache[$url] = $this->espn->fetch($url);
        } catch (HttpException $e) {
            if (in_array($e->httpStatus, [403, 429], true)) {
                throw $e;   // ESPN is refusing: stop the run
            }
            if (!$quiet) {
                $this->say('  ! ' . $e->getMessage());
            }
            return $this->cache[$url] = null;
        }
    }

    /** @return array{0:bool,1:?string} */
    private static function title(string $typeText): array
    {
        if (!preg_match('/title/i', $typeText)) {
            return [false, null];
        }
        return [true, preg_match('/interim/i', $typeText) ? 'interim' : 'undisputed'];
    }

    /** ESPN reports unknown height/reach/weight as 0. */
    public static function positive(mixed $v): ?float
    {
        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    /** ESPN reports unknown text fields as "--". */
    public static function text(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : null;
        return ($v === null || $v === '' || preg_match('/^[-–—\s]+$/u', $v)) ? null : $v;
    }

    private function say(string $m): void
    {
        if ($this->log) {
            ($this->log)($m);
        }
    }

    private static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
