<?php

namespace App\Game\Parsers;

use App\Game\Data\SkillInfo;
use App\Game\Exceptions\ParseException;

/**
 * Parses skills_info.php?id= — the character's CURRENT (level-scaled,
 * modifier-adjusted) values plus the authoritative recharge signal
 * "This skill is recharging. {n} minutes remaining."
 */
class SkillInfoParser
{
    public function parse(string $body): SkillInfo
    {
        if (! preg_match('/<h5>\s*(.+?)\s*(?:—|-)?\s*Level\s+(\d+)\s*<\/h5>/', $body, $heading)) {
            throw new ParseException('skills_info.php response has no "{name} Level {n}" heading: '.substr($body, 0, 200));
        }

        preg_match('/Level\s+\d+<\/h5>\s*(.*?)\s*<\/div>/s', $body, $description);
        preg_match('/Rage Cost:<\/b><br>\s*(\d+)/s', $body, $rage);
        preg_match('/Cooldown:<\/b><br>\s*([\d,]+)\s*mins/s', $body, $cooldown);
        preg_match('/Duration:<\/b><br>\s*([\d,]+)\s*mins/s', $body, $duration);
        preg_match('/recharging\.\s*([\d,]+)\s*minutes?\s+remaining/i', $body, $recharge);

        return new SkillInfo(
            name: trim($heading[1]),
            level: (int) $heading[2],
            description: trim($description[1] ?? ''),
            rageCost: (int) ($rage[1] ?? 0),
            cooldownMinutes: isset($cooldown[1]) ? $this->toInt($cooldown[1]) : null,
            durationMinutes: isset($duration[1]) ? $this->toInt($duration[1]) : null,
            rechargingMinutesRemaining: isset($recharge[1]) ? $this->toInt($recharge[1]) : null,
            hasNextLevel: str_contains($body, '>Next Level<'),
            learned: ! str_contains($body, 'not learned this skill'),
        );
    }

    private function toInt(string $value): int
    {
        return (int) str_replace(',', '', $value);
    }
}
