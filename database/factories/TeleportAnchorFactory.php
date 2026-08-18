<?php

namespace Database\Factories;

use App\Game\Enums\TeleportKind;
use App\Models\Room;
use App\Models\TeleportAnchor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeleportAnchor>
 */
class TeleportAnchorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => TeleportKind::Item,
            'game_item_id' => fake()->unique()->numberBetween(1000, 9999),
            'skill_id' => null,
            'name' => fake()->words(2, true),
            'room_id' => Room::factory(),
            'required_level' => null,
            'rage_cost' => 0,
            'cooldown_minutes' => 0,
            'description' => 'Teleports you to '.fake()->streetName().'.',
            'source' => 'observed',
            'first_seen_at' => now(),
            'last_verified_at' => now(),
        ];
    }

    /**
     * An anchor whose landing room has never been observed — usable as a
     * discovery target, but not for planning.
     */
    public function undiscovered(): static
    {
        return $this->state(fn (): array => ['room_id' => null]);
    }

    /**
     * The Teleport skill (id 27): 100 rage on a 60-minute cooldown, one row
     * per destination in the character's dropdown.
     */
    public function skill(int $skillId = 27): static
    {
        return $this->state(fn (): array => [
            'kind' => TeleportKind::Skill,
            'game_item_id' => null,
            'skill_id' => $skillId,
            'rage_cost' => 100,
            'cooldown_minutes' => 60,
            'description' => null,
        ]);
    }
}
