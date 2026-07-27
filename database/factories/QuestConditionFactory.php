<?php

namespace Database\Factories;

use App\Game\Enums\QuestObjectiveType;
use App\Models\Quest;
use App\Models\QuestCondition;
use App\Models\QuestStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestCondition>
 */
class QuestConditionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quest_step_id' => QuestStep::factory(),
            'quest_id' => Quest::factory(),
            'position' => 1,
            'type' => QuestObjectiveType::Kill,
            'target' => fake()->unique()->words(2, true),
            'amount' => fake()->numberBetween(1, 50),
        ];
    }
}
