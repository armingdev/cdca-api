<?php

namespace App\Game\Parsers;

/**
 * A successful cast_skills.php cast returns the skills page containing
 * `Status: You just cast {skillName}`. Anything else (rage too low, on
 * cooldown, not learned) lacks that marker.
 */
class CastConfirmationParser
{
    public function castSucceeded(string $body): bool
    {
        return $this->castSkillName($body) !== null;
    }

    public function castSkillName(string $body): ?string
    {
        return preg_match('/You just cast\s+([^<.\n]+)/i', $body, $m)
            ? trim($m[1])
            : null;
    }
}
