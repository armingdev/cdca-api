<?php

namespace App\Game\Data;

use App\Game\Enums\SkillSchool;

/**
 * Outcome of a full skill sync: how many per-character rows were updated and
 * the page-wide state that came with the tabs.
 */
final readonly class SkillSyncResult
{
    public function __construct(
        public int $rowsSynced,
        public int $skillsDiscovered,
        public ?int $skillPoints,
        public ?SkillSchool $school,
        public int $activeBuffs,
    ) {}
}
