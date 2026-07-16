<?php

namespace Database\Factories;

use App\Game\Enums\BattleOutcome;
use App\Models\BattleEvent;
use App\Models\Character;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BattleEvent>
 */
class BattleEventFactory extends Factory
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
            'mob_id' => null,
            'room_id' => null,
            'battle_id' => fake()->unique()->numberBetween(1, 2 ** 40),
            'outcome' => BattleOutcome::Win,
            'exp_gained' => fake()->numberBetween(100, 5000),
            'gold_gained' => fake()->numberBetween(0, 500),
            'drop_name' => null,
            'fail_reason' => null,
            'occurred_at' => now(),
        ];
    }

    public function loss(): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => BattleOutcome::Loss,
            'exp_gained' => null,
            'gold_gained' => null,
        ]);
    }

    public function failed(string $reason = 'That mob is already dead!'): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => BattleOutcome::Failed,
            'battle_id' => null,
            'exp_gained' => null,
            'gold_gained' => null,
            'fail_reason' => $reason,
        ]);
    }
}
