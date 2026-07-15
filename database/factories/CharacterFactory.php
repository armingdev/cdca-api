<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\Rga;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rga_id' => Rga::factory(),
            'suid' => fake()->unique()->numberBetween(1000, 999999),
            'server_id' => 1,
            'name' => fake()->unique()->userName(),
            'level' => fake()->numberBetween(1, 95),
            'rage' => fake()->numberBetween(0, 50000),
            'exp' => fake()->numberBetween(0, 200_000_000_000),
            'crew' => fake()->optional()->company(),
            'current_room_id' => null,
            'last_stats_at' => null,
            'status' => null,
        ];
    }

    /**
     * A character on the Torax server.
     */
    public function torax(): static
    {
        return $this->state(fn (array $attributes) => [
            'server_id' => 2,
        ]);
    }
}
