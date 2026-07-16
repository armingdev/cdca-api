<?php

namespace Database\Factories;

use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mode' => RunMode::Mob,
            'config' => [
                'mob_names' => ['Kix Harvester'],
                'stop_rage' => 2500,
                'max_kills' => 0,
                'level_up' => false,
            ],
            'status' => RunStatus::Pending,
            'restart_every_minutes' => null,
            'start_at' => null,
            'last_started_at' => null,
        ];
    }

    public function restartEvery(int $minutes): static
    {
        return $this->state(fn (array $attributes) => [
            'restart_every_minutes' => $minutes,
        ]);
    }
}
