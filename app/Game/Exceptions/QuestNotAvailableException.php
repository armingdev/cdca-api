<?php

namespace App\Game\Exceptions;

/**
 * The requested quest is not in the giver's "Available Quests" list — the
 * character has either already completed it or never met its prerequisites.
 * Quest-list mode treats this as "skip to the next quest".
 */
class QuestNotAvailableException extends GameException
{
    public function __construct(
        public readonly int $questId,
        public readonly string $npcName,
    ) {
        parent::__construct("Quest {$questId} is not available at {$npcName} (already completed or wrong giver).");
    }
}
