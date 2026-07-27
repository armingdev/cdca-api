<?php

namespace App\Game\Data;

/**
 * A full quest as rendered by show_quest.php?quest={id}: header (name, level
 * requirement, prerequisite) plus every step in order. The page is public —
 * no game session is required to fetch it.
 */
final readonly class QuestDetail
{
    /**
     * @param  list<QuestStepDetail>  $steps
     */
    public function __construct(
        public int $gameQuestId,
        public string $name,
        public ?int $requiredLevel,
        public ?string $prerequisite,
        public array $steps,
    ) {}

    /** The quest-giver: the NPC of the first step. */
    public function giver(): ?string
    {
        return $this->steps[0]->npc ?? null;
    }

    public function totalExp(): int
    {
        return array_sum(array_map(fn (QuestStepDetail $step) => $step->expReward ?? 0, $this->steps));
    }
}
