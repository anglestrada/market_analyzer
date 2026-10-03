<?php
declare(strict_types=1);

/**
 * Scores ESPN text (news headlines/descriptions) with the keyword rules and works out whether it fits the drop.
 *
 * Every active keyword adds to the relevance score. Keywords with a polarity (good / bad for the fighter the
 * text is about) also give a direction: "Talbott withdraws" is bad for Talbott, so it fits a drop in Talbott's
 * YES price; "Talbott looks sharp" points the other way.
 */
final class LiveSignals
{
    private ?array $rules = null;

    public function __construct(private PDO $db) {}

    /**
     * @return array{net:int, relevance:int, stance:string, fighter:?string, matched:string[]}|null
     *         null when the text names no fighter in this bout or matches no keyword.
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
        if (!$kept) {
            return null;
        }

        $net       = 0;
        $relevance = 0;
        $matched   = [];
        $about     = [];
        foreach ($kept as $h) {
            $relevance += (int) $h['rule']['score'];
            $matched[]  = $h['rule']['keyword'];
            $sign = ['good' => 1, 'bad' => -1][$h['rule']['polarity']] ?? 0;
            $fighter = $ctx->fighterAt($mentions, $h['start']);
            if ($sign === 0 || $fighter === null) {
                continue;
            }
            // Good for the opponent = bad for the subject.
            $forSubject = ($ctx->subject !== null && $fighter !== $ctx->subject) ? -$sign : $sign;
            $net += $forSubject * (int) $h['rule']['score'];
            $about[$fighter] = true;
        }

        $expected = $ctx->expectedDirection();
        $dir      = $net <=> 0;
        $stance   = ($expected === null || $dir === 0) ? 'neutral' : ($dir === $expected ? 'supports' : 'contradicts');

        return [
            'net'       => $net,
            'relevance' => $relevance,
            'stance'    => $stance,
            'fighter'   => count($about) === 1 ? array_key_first($about) : null,
            'matched'   => array_values(array_unique($matched)),
        ];
    }

    private function rules(): array
    {
        return $this->rules ??= $this->db->query(
            'SELECT keyword, score, polarity FROM keywords WHERE is_active = TRUE ORDER BY score DESC'
        )->fetchAll();
    }
}
