<?php

namespace App\Game\Engine;

/**
 * One QuestListRunner invocation's outcome. The quest and kill tallies count
 * this invocation only — what the job's "did this pass make progress" checks
 * need — while $progress carries the run's cumulative ones.
 */
final readonly class QuestListRunSummary
{
    public function __construct(
        public bool $completed,
        public int $questsCompleted,
        public int $questsSkipped,
        public int $kills,
        public string $stopReason,
        public RunEndReason $endReason,
        /** Everything the next job needs to carry on from here. */
        public QuestListProgress $progress = new QuestListProgress,
    ) {}

    /** Quests still waiting for a level or a prerequisite when the run stopped. */
    public function questsDeferred(): int
    {
        return count($this->progress->deferred);
    }
}
