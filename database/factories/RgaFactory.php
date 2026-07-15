<?php

namespace Database\Factories;

use App\Models\Rga;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rga>
 */
class RgaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'password' => 'secret-password',
            'cookies' => null,
            'status' => Rga::STATUS_ACTIVE,
            'last_login_at' => null,
        ];
    }

    /**
     * An RGA with a captured game session.
     */
    public function withSession(): static
    {
        return $this->state(fn (array $attributes) => [
            'cookies' => [
                'rg_sess_id' => fake()->md5(),
                'token' => fake()->md5(),
                'cuserid2' => (string) fake()->randomNumber(4),
                'owip' => fake()->ipv4(),
            ],
            'last_login_at' => now(),
        ]);
    }

    /**
     * An RGA whose session was invalidated (booted).
     */
    public function invalid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Rga::STATUS_INVALID,
        ]);
    }
}
