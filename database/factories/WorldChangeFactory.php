<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\WorldChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorldChange>
 */
class WorldChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'field' => 'north',
            'old_value' => null,
            'new_value' => (string) fake()->numberBetween(1, 50000),
            'character_id' => null,
            'observed_at' => now(),
        ];
    }
}
