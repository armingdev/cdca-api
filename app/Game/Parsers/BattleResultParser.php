<?php

namespace App\Game\Parsers;

use App\Game\Data\BattleResult;
use App\Game\Enums\BattleOutcome;
use App\Game\Exceptions\ParseException;

/**
 * Parses the battle-result page's JS vars.
 *
 * **`var successful = 0|1` is the authoritative outcome** (VERIFIED
 * 2026-08-25 from a live PvP page). The page emits *both* outcome branches as
 * literal JS and picks between them at runtime:
 *
 *     if (successful) { attacker_result = "Win!";      defender_result = "Defeated!"; }
 *     else            { attacker_result = "Defeated!"; defender_result = "Win!"; }
 *
 * That is why `attacker_result` "lies": a naive first-match regex reads the
 * dead branch. It never lied — it was read wrong.
 *
 * The exp/`has weakened` rules stay as a fallback for pages that carry no
 * `successful` flag. Neither can classify a PvP fight: `has weakened` appears
 * on wins and losses alike, and the PvE exp phrasing never appears at all —
 * PvP words its reward as "{name} stripped {N}xp and gained {N}xp".
 */
class BattleResultParser
{
    public function parse(string $html): BattleResult
    {
        $battleResult = $this->jsVar($html, 'battle_result');

        if ($battleResult === null) {
            throw new ParseException('Battle page has no battle_result var: '.substr(strip_tags($html), 0, 200));
        }

        // PvP phrases its rewards differently, and wraps the numbers in <font>
        // tags, so match against a tag-stripped copy:
        //   "RealLinuXX stripped 14484xp and gained 14484xp"
        $plain = $this->plainText($battleResult);

        $expGained = $this->extractInt($battleResult, '/has gained ([\d,]+) experience!/')
            ?? $this->extractInt($plain, '/gained ([\d,]+)xp/i');
        $expStripped = $this->extractInt($plain, '/stripped ([\d,]+)xp/i');
        $goldGained = $this->extractInt($battleResult, '/gained ([\d,]+) gold!/');

        return new BattleResult(
            outcome: $this->classify($html, $battleResult, $expGained),
            attackerName: $this->jsVar($html, 'attacker_name'),
            defenderName: $this->jsVar($html, 'defender_name'),
            expGained: $expGained,
            expStripped: $expStripped,
            goldGained: $goldGained,
            statGains: $this->extractStatGains($battleResult),
            dropName: $this->extractDrop($html),
            rawBattleResult: $battleResult,
        );
    }

    private function classify(string $html, string $battleResult, ?int $expGained): BattleOutcome
    {
        // The server's own verdict, when the page states it.
        $successful = $this->successFlag($html);

        if ($successful !== null) {
            return $successful ? BattleOutcome::Win : BattleOutcome::Loss;
        }

        if ($expGained !== null) {
            return BattleOutcome::Win;
        }

        if (str_contains($battleResult, 'has weakened')) {
            return BattleOutcome::Loss;
        }

        return BattleOutcome::Unknown;
    }

    /**
     * `var successful = 0|1` — set once per page, from the attacker's side
     * (`var viewer = "attacker"` on the page we fetch after our own attack).
     */
    private function successFlag(string $html): ?bool
    {
        return preg_match('/var\s+successful\s*=\s*(\d+)\s*;/', $html, $m)
            ? $m[1] !== '0'
            : null;
    }

    private function jsVar(string $html, string $name): ?string
    {
        return preg_match('/var\s+'.$name.'\s*=\s*"(.*?)";/s', $html, $matches)
            ? $matches[1]
            : null;
    }

    /** battle_result with its markup removed, for text matching. */
    private function plainText(string $battleResult): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $battleResult) ?? $battleResult;

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    private function extractInt(string $text, string $pattern): ?int
    {
        return preg_match($pattern, $text, $matches)
            ? (int) str_replace(',', '', $matches[1])
            : null;
    }

    /**
     * Stat gains render as "{name} gained {N} {stat}" lines (gold and
     * experience have their own phrasing and are excluded here).
     *
     * @return array<string, int>
     */
    private function extractStatGains(string $battleResult): array
    {
        $gains = [];

        if (preg_match_all('/gained ([\d,]+) (?!gold)([a-z ]+?)(?:<|$)/i', $battleResult, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $stat = trim($match[2]);

                if ($stat !== '' && $stat !== 'experience') {
                    $gains[$stat] = (int) str_replace(',', '', $match[1]);
                }
            }
        }

        return $gains;
    }

    /**
     * Drops live outside the JS vars: <div id="found_items">…Found {item}…</div>.
     */
    private function extractDrop(string $html): ?string
    {
        if (! preg_match('/<div id="found_items">(.*?)<\/div>/s', $html, $div)) {
            return null;
        }

        return preg_match('/Found\s+(.+?)</', $div[1].'<', $matches)
            ? trim($matches[1])
            : null;
    }
}
