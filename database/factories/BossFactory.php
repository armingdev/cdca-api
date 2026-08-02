<?php

namespace Database\Factories;

use App\Models\Boss;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Boss>
 */
class BossFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nick = fake()->unique()->firstName();

        return [
            'id' => fake()->unique()->numberBetween(100, 999),
            'name' => $nick.', '.fake()->jobTitle(),
            'nick' => $nick,
            'rage_to_join' => fake()->numberBetween(500, 5000),
        ];
    }
}
