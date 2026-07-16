<?php

namespace App\Game\Engine;

final readonly class QuestRunSummary
{
    public function __construct(
        public bool $completed,
        public int $stepsCompleted,
        public int $expGained,
        public int $kills,
        public string $stopReason,
        public bool $externallyStopped = false,
    ) {}
}
