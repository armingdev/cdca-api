<?php

namespace App\Game\Data;

/**
 * One entry of an NPC popup's "Available Quests" list.
 */
final readonly class AvailableQuest
{
    public function __construct(
        public int $questId,
        public int $firstStepId,
        public int $npcId,
        public ?string $name,
    ) {}
}
