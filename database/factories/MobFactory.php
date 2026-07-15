<?php

namespace Database\Factories;

use App\Models\Mob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mob>
 */
class MobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_mob_id' => fake()->unique()->numberBetween(1, 99999),
            'name' => fake()->unique()->words(2, true),
            'level' => fake()->numberBetween(1, 120),
            'rage_cost' => fake()->numberBetween(10, 5000),
            'type' => 0,
            'can_form' => false,
            'image' => 'mobs/'.fake()->word().'.jpg',
            'last_seen_at' => now(),
        ];
    }

    /**
     * A raid/god mob.
     */
    public function raid(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 1,
            'can_form' => true,
            'level' => fake()->numberBetween(120, 200),
        ]);
    }
}
