<?php

namespace App\Game\Data;

use App\Game\Enums\QuestObjectiveType;

/**
 * One condition line of a show_quest.php step ("Kill: 50 Sickly Aequora",
 * "Collect: 1 Holy Elemental Crystal").
 */
final readonly class QuestCondition
{
    public function __construct(
        public QuestObjectiveType $type,
        public string $target,
        public int $amount,
    ) {}

    /**
     * @return array{type: string, target: string, amount: int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'target' => $this->target,
            'amount' => $this->amount,
        ];
    }
}
