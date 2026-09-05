<?php

namespace App\Game\Data;

use App\Game\Enums\QuestObjectiveType;

/**
 * One objective line of a quest step ("Street Crawler: 0/5 killed").
 *
 * `mobId` and `conditionId` are the game's own ids, and only the quest tracker
 * (`world_questHelper.php`) supplies them — a step page parsed from
 * `mob_talk.php` names its objectives in prose and leaves both null.
 */
final readonly class QuestObjective
{
    public function __construct(
        public QuestObjectiveType $type,
        public string $target,
        public int $current,
        public int $required,
        public bool $complete,
        public ?int $mobId = null,
        public ?int $conditionId = null,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->required - $this->current);
    }
}
