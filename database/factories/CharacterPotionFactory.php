<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\CharacterPotion;
use App\Models\Potion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CharacterPotion>
 */
class CharacterPotionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'character_id' => Character::factory(),
            'potion_id' => Potion::factory(),
            'iid' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'quantity' => 5,
            'active_until' => null,
            'active_synced_at' => null,
            'last_activated_at' => null,
            'refusals' => 0,
            'refused_until' => null,
            'synced_at' => now(),
        ];
    }
}
