<?php

namespace App\Game\Engine;

final readonly class QuestListRunSummary
{
    public function __construct(
        public bool $completed,
        public int $questsCompleted,
        public int $questsSkipped,
        public int $kills,
        public string $stopReason,
        public bool $externallyStopped = false,
    ) {}
}
