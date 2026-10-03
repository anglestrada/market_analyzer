<?php
declare(strict_types=1);

/**
 * Who is in the fight, which fighter this market is about, and what a drop means for him.
 *
 * Kalshi UFC markets are "Will <fighter> win?": YES = the market's fighter (the subject), NO = his opponent.
 *   YES dropped → bad news for the subject (or good news for the opponent)   → expected direction −1
 *   NO dropped  → good news for the subject (or bad news for the opponent)   → expected direction +1
 */
final class FightContext
{
    /** @var array<string, string[]> fighter => name needles (full name, last name) */
    private array $needles = [];

    /**
     * @param string[] $opponents
     */
    public function __construct(
        public readonly ?string $subject,
        public readonly array $opponents,
        public readonly string $droppedSide,
    ) {
        foreach ($this->fighters() as $f) {
            $this->needles[$f] = self::needlesFor($f);
        }
    }

    /** @param array $mv movement row with yes_subtitle, event_id, event_title, market_title, dropped_side */
    public static function forMovement(PDO $db, array $mv): self
    {
        $valid   = fn(?string $n) => $n !== null && mb_strlen(trim($n)) > 2 && !in_array(strtolower(trim($n)), ['yes', 'no'], true);
        $subject = $valid($mv['yes_subtitle'] ?? null) ? trim($mv['yes_subtitle']) : null;

        $stmt = $db->prepare('SELECT DISTINCT yes_subtitle FROM markets WHERE event_id = :e AND yes_subtitle IS NOT NULL');
        $stmt->execute([':e' => $mv['event_id']]);
        $others = array_values(array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN)),
            fn($n) => $valid($n) && strcasecmp($n, (string) $subject) !== 0));

        if (!$others) {
            // "Talbott vs Rosas" in the event title → the name that isn't the subject
            $title = preg_replace('/^\s*\d+\s*:\s*|\s+—.*$|\b(UFC.*|Fight Night.*)$/iu', '', (string) $mv['event_title']);
            foreach (array_filter(array_map('trim', preg_split('/\s+vs\.?\s+/i', $title))) as $n) {
                if ($valid($n) && ($subject === null || !self::sameFighter($n, $subject))) {
                    $others[] = $n;
                }
            }
        }
        return new self($subject, array_slice(array_values(array_unique($others)), 0, 4), (string) $mv['dropped_side']);
    }

    /** @return string[] */
    public function fighters(): array
    {
        return array_values(array_filter(array_merge([$this->subject], $this->opponents)));
    }

    /** Full names and last names, for search queries. */
    public function searchTerms(): array
    {
        $terms = [];
        foreach ($this->needles as $list) {
            foreach ($list as $n) {
                $terms[strtolower($n)] = $n;
            }
        }
        return array_values($terms);
    }

    /** Last names only ("Talbott", "Rosas"), for short search queries. */
    public function lastNames(): array
    {
        return array_values(array_unique(array_map(fn($list) => end($list), $this->needles)));
    }

    /** +1 if the drop means good news for the subject, −1 if bad news. Null if the subject is unknown. */
    public function expectedDirection(): ?int
    {
        if ($this->subject === null) {
            return null;
        }
        return $this->droppedSide === 'yes' ? -1 : 1;
    }

    public function opponentLabel(): string
    {
        return $this->opponents ? implode(' / ', $this->opponents) : 'the opponent';
    }

    public function mentionsAny(string $text): bool
    {
        return $this->mentions($text) !== [];
    }

    /**
     * Byte offsets of every fighter mention.
     * @return array<string, int[]>
     */
    public function mentions(string $text): array
    {
        $out = [];
        foreach ($this->needles as $fighter => $list) {
            foreach ($list as $n) {
                if (preg_match_all(self::wordPattern($n), $text, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as [, $off]) {
                        $out[$fighter][] = $off;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Which fighter a phrase at $offset is about: the closest name before it ("Talbott looks sharp"),
     * otherwise the closest one after it ("Looking sharp, Talbott").
     */
    public function fighterAt(array $mentions, int $offset): ?string
    {
        $before = null; $bDist = PHP_INT_MAX;
        $after  = null; $aDist = PHP_INT_MAX;
        foreach ($mentions as $fighter => $offs) {
            foreach ($offs as $o) {
                if ($o <= $offset && $offset - $o < $bDist) {
                    [$before, $bDist] = [$fighter, $offset - $o];
                } elseif ($o > $offset && $o - $offset < $aDist) {
                    [$after, $aDist] = [$fighter, $o - $offset];
                }
            }
        }
        return $before ?? $after;
    }

    public static function wordPattern(string $phrase): string
    {
        return '/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/iu';
    }

    /** @return string[] full name and last name, without Jr./Sr. */
    private static function needlesFor(string $name): array
    {
        $clean = trim(preg_replace('/,?\s+\b(jr|sr|ii|iii)\.?$/i', '', trim($name)));
        $parts = preg_split('/\s+/', $clean);
        $last  = (string) end($parts);
        return array_values(array_unique(array_filter([$clean, mb_strlen($last) >= 3 ? $last : null])));
    }

    private static function sameFighter(string $a, string $b): bool
    {
        $la = self::needlesFor($a);
        $lb = self::needlesFor($b);
        return strcasecmp((string) end($la), (string) end($lb)) === 0;
    }
}
