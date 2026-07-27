<?php

namespace Database\Factories;

use App\Models\Quest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quest>
 */
class QuestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_quest_id' => fake()->unique()->numberBetween(1, 5000),
            'name' => fake()->unique()->words(3, true),
            'required_level' => fake()->numberBetween(1, 95),
            'prerequisite' => null,
            'giver' => fake()->name(),
            'steps_count' => 1,
            'total_exp' => fake()->numberBetween(0, 2_000_000),
            'last_mapped_at' => now(),
        ];
    }
}
