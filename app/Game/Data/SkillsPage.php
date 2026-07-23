<?php

namespace App\Game\Data;

/**
 * Parsed cast_skills.php page: one tab's skill rows plus the page-wide state
 * (skill points, active-buff panels, skill history log).
 */
final readonly class SkillsPage
{
    /**
     * @param  list<SkillRow>  $rows
     * @param  list<ActiveEffect>  $currentEffects
     * @param  list<ActiveEffect>  $castSkills
     * @param  list<SkillHistoryEntry>  $history
     */
    public function __construct(
        public array $rows,
        public ?int $skillPoints,
        public array $currentEffects,
        public array $castSkills,
        public array $history,
    ) {}
}
