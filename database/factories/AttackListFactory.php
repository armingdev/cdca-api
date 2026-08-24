<?php

namespace Database\Factories;

use App\Models\AttackList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttackList>
 */
class AttackListFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'server_id' => 1,
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
