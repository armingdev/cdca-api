<?php

namespace App\Game\Data;

/**
 * One step of a show_quest.php page: the NPC to talk to, their prompt
 * ("message") and reply, the conditions to meet, and the rewards granted on
 * completion. Accept/intro steps have no conditions.
 */
final readonly class QuestStepDetail
{
    /**
     * @param  list<QuestCondition>  $conditions
     * @param  list<QuestItemReward>  $itemRewards
     */
    public function __construct(
        public string $npc,
        public string $message,
        public array $conditions,
        public array $itemRewards,
        public ?int $expReward,
        public ?string $reply,
    ) {}
}
