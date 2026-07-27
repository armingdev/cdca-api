<?php

namespace App\Game\Data;

/**
 * One "find my target" toggle from the world_questHelper.php tracker —
 * the getQuestHelpData2(questid, mobid, itemname, stepid, conditionid)
 * arguments of an objective line. Kill objectives carry a mob id; collect
 * objectives carry the item name (mob id 0).
 */
final readonly class QuestHelperToggle
{
    public function __construct(
        public int $questId,
        public int $mobId,
        public string $itemName,
        public int $stepId,
        public int $conditionId,
    ) {}

    public function isCollect(): bool
    {
        return $this->itemName !== '';
    }
}
