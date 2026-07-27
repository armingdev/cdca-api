<?php

namespace Database\Factories;

use App\Models\Quest;
use App\Models\QuestStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestStep>
 */
class QuestStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quest_id' => Quest::factory(),
            'position' => 1,
            'npc' => fake()->name(),
            'message' => fake()->sentence(),
            'item_rewards' => [],
            'exp_reward' => null,
            'reply' => fake()->sentence(),
        ];
    }
}
