<?php

namespace App\Game\Data;

/**
 * One Skill Log row, e.g. "Trained Teleport Level 1" or "Cast Empower on X".
 */
final readonly class SkillHistoryEntry
{
    public function __construct(
        public string $timestamp,
        public string $action,
    ) {}
}
