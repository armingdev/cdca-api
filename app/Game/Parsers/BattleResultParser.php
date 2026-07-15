<?php

namespace App\Game\Parsers;

use App\Game\Data\BattleResult;
use App\Game\Enums\BattleOutcome;
use App\Game\Exceptions\ParseException;

/**
 * Parses the battle-result page's JS vars. Classification keys off the
 * battle_result TEXT (authoritative): win iff it contains an exp gain, loss
 * iff the defender weakened us with no exp gain. The attacker_result var
 * lies on re-fetched pages — never rely on it alone.
 */
class BattleResultParser
{
    public function parse(string $html): BattleResult
    {
        $battleResult = $this->jsVar($html, 'battle_result');

        if ($battleResult === null) {
            throw new ParseException('Battle page has no battle_result var: '.substr(strip_tags($html), 0, 200));
        }

        $expGained = $this->extractInt($battleResult, '/has gained ([\d,]+) experience!/');
        $goldGained = $this->extractInt($battleResult, '/gained ([\d,]+) gold!/');

        return new BattleResult(
            outcome: $this->classify($battleResult, $expGained),
            attackerName: $this->jsVar($html, 'attacker_name'),
            defenderName: $this->jsVar($html, 'defender_name'),
            expGained: $expGained,
            goldGained: $goldGained,
            statGains: $this->extractStatGains($battleResult),
            dropName: $this->extractDrop($html),
        );
    }

    private function classify(string $battleResult, ?int $expGained): BattleOutcome
    {
        if ($expGained !== null) {
            return BattleOutcome::Win;
        }

        if (str_contains($battleResult, 'has weakened')) {
            return BattleOutcome::Loss;
        }

        return BattleOutcome::Unknown;
    }

    private function jsVar(string $html, string $name): ?string
    {
        return preg_match('/var\s+'.$name.'\s*=\s*"(.*?)";/s', $html, $matches)
            ? $matches[1]
            : null;
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
