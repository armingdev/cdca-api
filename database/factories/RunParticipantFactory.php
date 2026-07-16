<?php

namespace Database\Factories;

use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunParticipant>
 */
class RunParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'character_id' => Character::factory(),
            'status' => RunStatus::Pending,
            'wins' => 0,
            'losses' => 0,
            'errors' => 0,
        ];
    }
}
