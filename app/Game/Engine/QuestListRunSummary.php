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
        public RunEndReason $endReason,
        public int $nextPosition = 0,
        /** Parked retries spent so far on the quest at $nextPosition. */
        public int $questRetries = 0,
    ) {}
}
