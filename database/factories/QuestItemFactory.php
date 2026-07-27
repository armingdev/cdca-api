<?php

namespace Database\Factories;

use App\Models\QuestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestItem>
 */
class QuestItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'source_mobs' => [fake()->words(2, true)],
        ];
    }
}
