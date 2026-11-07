<?php

namespace Database\Factories;

use App\Game\Enums\PotionKind;
use App\Models\Potion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Potion>
 */
class PotionFactory extends Factory
{
    /**
     * Define the model's default state: a one-hour buff potion.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_item_id' => fake()->unique()->numberBetween(10000, 99999),
            'name' => fake()->unique()->words(2, true).' Potion',
            'image' => 'items/potion.jpg',
            'level' => 0,
            'player_bound' => false,
            'description' => '+425 ATK, 1,250 HP and 5% Crit for 60 mins.',
            'kind' => PotionKind::Buff,
            'duration_minutes' => 60,
            'rage_amount' => null,
        ];
    }

    /**
     * An instant rage potion.
     */
    public function rage(int $amount = 1000): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => "Warning: Awards {$amount} Rage upon consumption.",
            'kind' => PotionKind::Rage,
            'duration_minutes' => null,
            'rage_amount' => $amount,
        ]);
    }

    /**
     * A potion no run may use.
     */
    public function other(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => 'Used to kill Lord Narada and other Potion Gods.',
            'kind' => PotionKind::Other,
            'duration_minutes' => null,
            'rage_amount' => null,
        ]);
    }
}
