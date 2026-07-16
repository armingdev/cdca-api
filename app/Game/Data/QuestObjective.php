<?php

namespace App\Game\Data;

use App\Game\Enums\QuestObjectiveType;

/**
 * One objective line of a quest step ("Street Crawler: 0/5 killed").
 */
final readonly class QuestObjective
{
    public function __construct(
        public QuestObjectiveType $type,
        public string $target,
        public int $current,
        public int $required,
        public bool $complete,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->required - $this->current);
    }
}
