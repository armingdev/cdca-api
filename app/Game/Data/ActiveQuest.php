<?php

namespace App\Game\Data;

use App\Game\Enums\QuestObjectiveType;

/**
 * One in-progress quest as the tracker (`world_questHelper.php`) renders it:
 * the quest's id and name, the step the character currently stands on, and
 * that step's objective rows.
 *
 * This is the only authority on where a character actually *is* in a quest.
 * The quest-giver's popup is not: it lists a quest only while the current step
 * belongs to that mob, so a quest several steps in — whose step now belongs to
 * a different mob entirely — reads as "not offered" there.
 *
 * VERIFIED 2026-09-05: a started quest is always listed here, and when talking
 * is the next action the step renders a Talk row naming the mob to visit
 * ("Find {Name}" to continue, "Return to {Name}" to turn in). The Talk row
 * appears only once every kill/collect row of the step is complete.
 */
final readonly class ActiveQuest
{
    /**
     * @param  list<QuestObjective>  $objectives
     */
    public function __construct(
        public int $questId,
        public ?string $name,
        public int $stepId,
        public array $objectives,
    ) {}

    /**
     * The mob the step wants talked to, when the game is asking for one.
     */
    public function talkTarget(): ?string
    {
        foreach ($this->objectives as $objective) {
            if ($objective->type === QuestObjectiveType::Talk) {
                return $objective->target;
            }
        }

        return null;
    }

    /**
     * The farmable objectives still short of their count. Talk rows are never
     * included: they are an instruction to walk somewhere, not something to
     * grind.
     *
     * @return list<QuestObjective>
     */
    public function unmetObjectives(): array
    {
        return array_values(array_filter(
            $this->objectives,
            fn (QuestObjective $objective) => ! $objective->complete
                && $objective->type !== QuestObjectiveType::Talk,
        ));
    }

    /**
     * The "find my target" toggles of this quest's objectives — the shape the
     * quest-helper compass endpoint takes.
     *
     * @return list<QuestHelperToggle>
     */
    public function toggles(): array
    {
        return array_map(fn (QuestObjective $objective) => new QuestHelperToggle(
            questId: $this->questId,
            mobId: $objective->mobId ?? 0,
            itemName: $objective->type === QuestObjectiveType::Collect ? $objective->target : '',
            stepId: $this->stepId,
            conditionId: $objective->conditionId ?? 0,
        ), $this->objectives);
    }
}
