<?php

namespace Database\Factories;

use App\Models\Crew;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Crew>
 */
class CrewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => 1,
            'game_crew_id' => fake()->unique()->numberBetween(1, 99999),
            'name' => fake()->unique()->company(),
            'leader' => fake()->userName(),
            'total_members' => fake()->numberBetween(1, 60),
            'average_level' => fake()->numberBetween(1, 95),
            'members_synced_at' => now(),
        ];
    }

    /**
     * A crew on the Torax server.
     */
    public function torax(): static
    {
        return $this->state(fn (array $attributes) => [
            'server_id' => 2,
        ]);
    }
}
