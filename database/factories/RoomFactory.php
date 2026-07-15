<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 50000),
            'name' => fake()->streetName(),
            'north' => null,
            'east' => null,
            'south' => null,
            'west' => null,
            'doors' => null,
            'is_gated' => false,
            'gate_reason' => null,
            'source' => 'spider',
            'first_seen_at' => now(),
            'last_verified_at' => now(),
        ];
    }

    /**
     * A room the spider could not enter.
     */
    public function gated(string $reason = 'you must be carrying an item to enter'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_gated' => true,
            'gate_reason' => $reason,
            'last_verified_at' => null,
        ]);
    }
}
