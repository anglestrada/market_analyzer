<?php
declare(strict_types=1);

/**
 * Reddit (app-only OAuth) — r/MMA live discussion threads plus posts that name a fighter.
 * Needs REDDIT_CLIENT_ID / REDDIT_CLIENT_SECRET from a "script" app at https://www.reddit.com/prefs/apps.
 */
final class RedditClient
{
    private ?string $token = null;
    private int $tokenExpires = 0;

    public function __construct(
        private ?string $clientId = null,
        private ?string $clientSecret = null,
        private string $subreddit = REDDIT_SUBREDDIT,
        private string $apiUrl = 'https://oauth.reddit.com',
        private string $tokenUrl = 'https://www.reddit.com/api/v1/access_token',
    ) {
        $this->clientId     ??= REDDIT_CLIENT_ID;
        $this->clientSecret ??= REDDIT_CLIENT_SECRET;
    }

    public function isConfigured(): bool
    {
        return $this->clientId && $this->clientSecret;
    }

    /**
     * Comments and posts from $from..$to that mention a fighter.
     *
     * @return array<int, array{text:string, at:?string, url:?string, author:?string, engagement:int}>
     */
    public function postsAbout(FightContext $ctx, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $fromTs = $from->getTimestamp();
        $toTs   = $to->getTimestamp() + 60;   // comments land a little after the price
        $out    = [];
        $seen   = [];

        $keep = function (string $text, int $created, ?string $permalink, ?string $author, int $score) use (&$out, &$seen, $ctx, $fromTs, $toTs) {
            if ($created < $fromTs || $created > $toTs || $text === '' || !$ctx->mentionsAny($text)) {
                return;
            }
            $key = $permalink ?? md5($text);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [
                'text'       => $text,
                'at'         => gmdate(DATE_ATOM, $created),
                'url'        => $permalink ? 'https://www.reddit.com' . $permalink : null,
                'author'     => $author ? 'u/' . $author : null,
                'engagement' => $score,
            ];
        };

        // 1. Live discussion threads around the drop → newest top-level comments.
        foreach ($this->discussionThreads($ctx, $fromTs) as $thread) {
            $data = $this->get('/r/' . rawurlencode($this->subreddit) . '/comments/' . rawurlencode($thread['id']), [
                'sort' => 'new', 'limit' => 500, 'depth' => 1, 'raw_json' => 1,
            ]);
            foreach ($data[1]['data']['children'] ?? [] as $c) {
                if (($c['kind'] ?? '') !== 't1') {
                    continue;
                }
                $d = $c['data'];
                $keep((string) ($d['body'] ?? ''), (int) ($d['created_utc'] ?? 0), $d['permalink'] ?? null,
                    $d['author'] ?? null, (int) ($d['score'] ?? 0));
            }
        }

        // 2. Posts in the subreddit that name a fighter.
        $q = implode(' OR ', array_map(fn($n) => str_contains($n, ' ') ? '"' . $n . '"' : $n, $ctx->lastNames()));
        if ($q !== '') {
            $data = $this->get('/r/' . rawurlencode($this->subreddit) . '/search', [
                'q' => $q, 'restrict_sr' => 1, 'sort' => 'new', 't' => 'week', 'limit' => 50, 'raw_json' => 1,
            ]);
            foreach ($data['data']['children'] ?? [] as $c) {
                $d = $c['data'] ?? [];
                $keep(trim(($d['title'] ?? '') . "\n" . ($d['selftext'] ?? '')), (int) ($d['created_utc'] ?? 0),
                    $d['permalink'] ?? null, $d['author'] ?? null, (int) ($d['score'] ?? 0));
            }
        }

        return $out;
    }

    /** Up to 3 "… Discussion Thread" posts created in the 18 hours before the drop, best match first. */
    private function discussionThreads(FightContext $ctx, int $aroundTs): array
    {
        $data = $this->get('/r/' . rawurlencode($this->subreddit) . '/search', [
            'q' => 'title:discussion', 'restrict_sr' => 1, 'sort' => 'new', 't' => 'week', 'limit' => 25, 'raw_json' => 1,
        ]);
        $threads = [];
        foreach ($data['data']['children'] ?? [] as $c) {
            $d       = $c['data'] ?? [];
            $title   = (string) ($d['title'] ?? '');
            $created = (int) ($d['created_utc'] ?? 0);
            if (empty($d['id']) || $created > $aroundTs + 3600 || $created < $aroundTs - 18 * 3600
                || stripos($title, 'post fight') !== false || stripos($title, 'post-fight') !== false) {
                continue;
            }
            $rank = (int) $ctx->mentionsAny($title) * 4
                + (int) (stripos($title, 'live') !== false) * 2
                + (int) (bool) preg_match('/main card|prelims|ufc/i', $title);
            $threads[] = ['id' => (string) $d['id'], 'title' => $title, 'rank' => $rank, 'created' => $created];
        }
        usort($threads, fn($a, $b) => [$b['rank'], $b['created']] <=> [$a['rank'], $a['created']]);
        return array_slice($threads, 0, 3);
    }

    private function get(string $path, array $query): array
    {
        return Http::json('GET', $this->apiUrl . $path . '?' . http_build_query($query), [
            'Authorization: Bearer ' . $this->token(),
            'User-Agent: ' . REDDIT_USER_AGENT,
        ]);
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExpires - 60) {
            return $this->token;
        }
        if (!$this->isConfigured()) {
            throw new HttpException('REDDIT_CLIENT_ID / REDDIT_CLIENT_SECRET are not set in .env.');
        }
        $data = Http::json('POST', $this->tokenUrl, [
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'User-Agent: ' . REDDIT_USER_AGENT,
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query(['grant_type' => 'client_credentials']));

        if (empty($data['access_token'])) {
            throw new HttpException('Reddit did not return an access token' . (isset($data['error']) ? ': ' . $data['error'] : '.'));
        }
        $this->token        = (string) $data['access_token'];
        $this->tokenExpires = time() + (int) ($data['expires_in'] ?? 3600);
        return $this->token;
    }
}
