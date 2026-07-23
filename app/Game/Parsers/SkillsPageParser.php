<?php

namespace App\Game\Parsers;

use App\Game\Data\ActiveEffect;
use App\Game\Data\SkillHistoryEntry;
use App\Game\Data\SkillRow;
use App\Game\Data\SkillsPage;

/**
 * Parses a cast_skills.php tab: skill rows with (trained+bonus) levels and
 * Train links, the toolbar skill-point balance, the Current Effects / Cast
 * Skills buff panels, and the Skill Log history table.
 */
class SkillsPageParser
{
    public function parse(string $body): SkillsPage
    {
        return new SkillsPage(
            rows: $this->parseRows($body),
            skillPoints: $this->parseSkillPoints($body),
            currentEffects: $this->parseEffects($body, 'By'),
            castSkills: $this->parseEffects($body, 'On'),
            history: $this->parseHistory($body),
        );
    }

    /**
     * @return list<SkillRow>
     */
    private function parseRows(string $body): array
    {
        preg_match_all(
            '/<li[^>]*onclick="loadskill\((\d+)\);">(.*?)<\/li>/s',
            $body,
            $matches,
            PREG_SET_ORDER,
        );

        $rows = [];

        foreach ($matches as $match) {
            $id = (int) $match[1];
            $block = $match[2];

            if (! preg_match('/<h6[^>]*>\s*(.*?)(?:\s*\(([^)]+)\))?\s*<\/h6>/s', $block, $heading)) {
                continue;
            }

            [$trained, $bonus, $unlockLevel] = $this->parseLevels($heading[2] ?? '');

            preg_match('/<p class="mg-b-0">\s*(.*?)\s*<\/p>/s', $block, $description);

            $rows[] = new SkillRow(
                id: $id,
                name: trim($heading[1]),
                description: trim($description[1] ?? ''),
                trainedLevel: $trained,
                bonusLevel: $bonus,
                unlockLevel: $unlockLevel,
                trainable: (bool) preg_match('/C=2&(?:amp;)?T='.$id.'\b/', $block),
            );
        }

        return $rows;
    }

    /**
     * The parenthetical after a skill name: "1+8" (trained+bonus), "1"
     * (trained only), or "Unlock at 80" (character-level-gated).
     *
     * @return array{int, int, ?int}
     */
    private function parseLevels(string $parenthetical): array
    {
        if (preg_match('/^(\d+)\+(\d+)$/', $parenthetical, $m)) {
            return [(int) $m[1], (int) $m[2], null];
        }

        if (preg_match('/^(\d+)$/', $parenthetical, $m)) {
            return [(int) $m[1], 0, null];
        }

        if (preg_match('/Unlock at (\d+)/i', $parenthetical, $m)) {
            return [0, 0, (int) $m[1]];
        }

        return [0, 0, null];
    }

    private function parseSkillPoints(string $body): ?int
    {
        if (preg_match('/<b>Skill:<\/b><\/td><td>(\d+)/', $body, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Buff tooltips: "Cast By {name}" entries are the Current Effects panel
     * (buffs on this character, possibly cast by others); "Cast On {name}"
     * entries are the Cast Skills panel (skills this character cast).
     *
     * @return list<ActiveEffect>
     */
    private function parseEffects(string $body, string $preposition): array
    {
        preg_match_all(
            "/popup\\(event,'<b>Level (\\d+) ([^<]+)<\\/b>(.*?)Cast {$preposition} ([^']+)'/s",
            $body,
            $matches,
            PREG_SET_ORDER,
        );

        $effects = [];

        foreach ($matches as $match) {
            $effects[] = new ActiveEffect(
                name: trim($match[2]),
                level: (int) $match[1],
                minutesLeft: $this->parseMinutesLeft($match[3]),
                castBy: $preposition === 'By' ? trim($match[4]) : null,
                castOn: $preposition === 'On' ? trim($match[4]) : null,
            );
        }

        return $effects;
    }

    /**
     * "2 hours 51 mins left" → 171; "171 mins left" → 171; "3 hours left" → 180.
     */
    private function parseMinutesLeft(string $text): int
    {
        if (! preg_match('/(?:(\d+)\s*hours?)?\s*(?:(\d+)\s*mins?)?\s*left/', $text, $m)) {
            return 0;
        }

        return ((int) ($m[1] ?? 0)) * 60 + (int) ($m[2] ?? 0);
    }

    /**
     * @return list<SkillHistoryEntry>
     */
    private function parseHistory(string $body): array
    {
        preg_match_all(
            '/<tr><td><small>([^<]+)<\/small><\/td><td>([^<]+)<\/td>/',
            $body,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            fn (array $m) => new SkillHistoryEntry(timestamp: trim($m[1]), action: trim($m[2])),
            $matches,
        );
    }
}
