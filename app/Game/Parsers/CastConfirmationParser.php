<?php

namespace App\Game\Parsers;

use Illuminate\Support\Str;

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

    /**
     * Confirmation for one specific skill. The bare marker is not enough: the
     * page can carry a status line left over from an earlier cast, and taking
     * that as success stamps last_cast_at for a skill that never went off —
     * which then reads as "on cooldown" and blocks the retry.
     */
    public function castSucceededFor(string $body, string $skillName): bool
    {
        $confirmed = $this->castSkillName($body);

        return $confirmed !== null && Str::lower($confirmed) === Str::lower(trim($skillName));
    }

    public function castSkillName(string $body): ?string
    {
        return preg_match('/You just cast\s+([^<.\n]+)/i', $body, $m)
            ? trim($m[1])
            : null;
    }
}
