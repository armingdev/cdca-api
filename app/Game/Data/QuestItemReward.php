<?php

namespace App\Game\Data;

/**
 * One item-reward line of a show_quest.php step
 * ("You have received 1 Primal Elemental Rune!").
 */
final readonly class QuestItemReward
{
    public function __construct(
        public string $name,
        public int $amount,
    ) {}

    /**
     * @return array{name: string, amount: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'amount' => $this->amount,
        ];
    }
}
