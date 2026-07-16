<?php

namespace App\Game\Data;

/**
 * A parsed mob_talk.php step. The single authoritative "can I advance?" signal
 * is the presence of the finish link — the game only renders it when the
 * step's objective (if any) is satisfied, or immediately for objective-less
 * accept/intro steps.
 */
final readonly class QuestStepPage
{
    /**
     * @param  list<QuestObjective>  $objectives
     * @param  list<string>  $rewards
     */
    public function __construct(
        public ?string $npcName,
        public ?string $questTitle,
        public string $dialog,
        public array $objectives,
        public ?string $finishLink,
        public ?string $continueLink,
        public ?int $npcId,
        public ?int $stepId,
        public array $rewards,
        public ?int $expReward,
    ) {}

    /**
     * The game shows the finish link only when the step can be completed now.
     */
    public function canAdvance(): bool
    {
        return $this->finishLink !== null;
    }

    public function hasObjectives(): bool
    {
        return $this->objectives !== [];
    }

    /**
     * @return list<QuestObjective>
     */
    public function unmetObjectives(): array
    {
        return array_values(array_filter($this->objectives, fn (QuestObjective $o) => ! $o->complete));
    }
}
