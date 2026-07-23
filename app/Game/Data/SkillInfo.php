<?php

namespace App\Game\Data;

/**
 * Parsed skills_info.php?id= detail. Values are the character's CURRENT
 * (level-scaled, modifier-adjusted) numbers, not the L1 catalog snapshot.
 * rechargingMinutesRemaining is the authoritative cooldown signal.
 */
final readonly class SkillInfo
{
    public function __construct(
        public string $name,
        public int $level,
        public string $description,
        public int $rageCost,
        public ?int $cooldownMinutes,
        public ?int $durationMinutes,
        public ?int $rechargingMinutesRemaining,
        public bool $hasNextLevel,
        public bool $learned,
    ) {}
}
