<?php

namespace Database\Factories;

use App\Models\CrewGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewGroup>
 */
class CrewGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leader_user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'invite_code' => CrewGroup::freshInviteCode(),
        ];
    }

    /**
     * The leader is a member too, as CrewGroup::found() makes it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (CrewGroup $group): void {
            $group->members()->syncWithoutDetaching([$group->leader_user_id]);
        });
    }
}
