<?php
declare(strict_types=1);

/**
 * Scores live posts (X, Reddit) with the "live" keyword rules and works out whether a post
 * fits the drop: "Talbott looks flat" fits a drop in Talbott's YES price; "Talbott looks sharp" doesn't.
 */
final class LiveSignals
{
    private ?array $rules = null;

    public function __construct(private PDO $db) {}

    /**
     * @return array{net:int, strength:int, stance:string, fighter:?string, matched:string[]}|null
     *         null when the post has no live keyword tied to a fighter in this bout.
     *         net > 0 = good for the market's fighter (the subject), net < 0 = bad for him.
     */
    public function analyze(string $text, FightContext $ctx): ?array
    {
        $mentions = $ctx->mentions($text);
        if (!$mentions) {
            return null;
        }

        // Find every phrase; when phrases overlap ("looks sharp" / "sharp") keep the longest.
        $hits = [];
        foreach ($this->rules() as $r) {
            if (preg_match_all(FightContext::wordPattern($r['keyword']), $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$str, $off]) {
                    $hits[] = ['rule' => $r, 'start' => $off, 'end' => $off + strlen($str)];
                }
            }
        }
        usort($hits, fn($a, $b) => ($b['end'] - $b['start']) <=> ($a['end'] - $a['start']));
        $kept = [];
        foreach ($hits as $h) {
            foreach ($kept as $k) {
                if ($h['start'] < $k['end'] && $k['start'] < $h['end']) {
                    continue 2;
                }
            }
            $kept[] = $h;
        }

        $net     = 0;
        $abs     = 0;
        $matched = [];
        $about   = [];
        foreach ($kept as $h) {
            $sign = ['good' => 1, 'bad' => -1][$h['rule']['polarity']] ?? 0;
            if ($sign === 0) {
                continue;
            }
            $fighter = $ctx->fighterAt($mentions, $h['start']);
            if ($fighter === null) {
                continue;
            }
            // Good for the opponent = bad for the subject.
            $forSubject = ($ctx->subject !== null && $fighter !== $ctx->subject) ? -$sign : $sign;
            $net += $forSubject * (int) $h['rule']['score'];
            $abs += (int) $h['rule']['score'];
            $matched[]       = $h['rule']['keyword'];
            $about[$fighter] = true;
        }
        if (!$matched) {
            return null;
        }

        $expected = $ctx->expectedDirection();
        $dir      = $net <=> 0;
        $stance   = ($expected === null || $dir === 0) ? 'neutral' : ($dir === $expected ? 'supports' : 'contradicts');

        return [
            'net'      => $net,
            'strength' => $abs,
            'stance'   => $stance,
            'fighter'  => count($about) === 1 ? array_key_first($about) : null,
            'matched'  => array_values(array_unique($matched)),
        ];
    }

    /**
     * Picks the best post that fits the drop and counts how many others agree / disagree.
     *
     * @param array<int, array{text:string, at:?string, url:?string, author:?string, engagement:int}> $posts
     * @return array evidence row values (status, stance, score, headline, detail, url, occurred_at, item_count, matched_keywords, raw)
     */
    public function summarize(array $posts, FightContext $ctx, string $sourceLabel): array
    {
        $support = [];
        $against = 0;
        foreach ($posts as $p) {
            $a = $this->analyze($p['text'], $ctx);
            if ($a === null) {
                continue;
            }
            if ($a['stance'] === 'supports') {
                $support[] = $p + ['analysis' => $a];
            } elseif ($a['stance'] === 'contradicts') {
                $against++;
            }
        }

        $checked = count($posts);
        if (!$support) {
            return [
                'status'     => 'none',
                'detail'     => "$checked post" . ($checked === 1 ? '' : 's') . ' checked'
                    . ($against ? ", $against pointed the other way" : ', none matched the live keyword rules'),
                'item_count' => $checked,
            ];
        }

        usort($support, fn($a, $b) => [abs($b['analysis']['net']), $b['engagement']] <=> [abs($a['analysis']['net']), $a['engagement']]);
        $best    = $support[0];
        $similar = count($support) - 1;
        $matched = array_values(array_unique(array_merge(...array_map(fn($p) => $p['analysis']['matched'], $support))));

        $detail = [];
        if ($best['author']) {
            $detail[] = $best['author'];
        }
        if ($similar > 0) {
            $detail[] = "$similar similar post" . ($similar === 1 ? '' : 's');
        }
        if ($against > 0) {
            $detail[] = "$against disagree" . ($against === 1 ? 's' : '');
        }
        $detail[] = "$checked checked";

        return [
            'status'           => 'found',
            'stance'           => 'supports',
            'score'            => min(20, abs($best['analysis']['net']) + min(5, $similar)),
            'headline'         => '“' . Http::snippet($best['text'], 200) . '”',
            'detail'           => implode(' · ', $detail),
            'url'              => $best['url'],
            'occurred_at'      => $best['at'],
            'item_count'       => count($support),
            'matched_keywords' => $matched,
            'raw'              => [
                'source'  => $sourceLabel,
                'checked' => $checked,
                'against' => $against,
                'top'     => array_map(fn($p) => [
                    'text' => Http::snippet($p['text'], 280), 'at' => $p['at'], 'url' => $p['url'],
                    'net'  => $p['analysis']['net'], 'matched' => $p['analysis']['matched'],
                ], array_slice($support, 0, 5)),
            ],
        ];
    }

    private function rules(): array
    {
        return $this->rules ??= $this->db->query(
            "SELECT keyword, score, polarity FROM keywords
              WHERE is_active = TRUE AND scope IN ('live', 'both') AND polarity <> 'neutral'"
        )->fetchAll();
    }
}
