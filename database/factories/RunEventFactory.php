<?php

namespace Database\Factories;

use App\Game\Enums\RunEventType;
use App\Models\Character;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\RunParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunEvent>
 */
class RunEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $participant = RunParticipant::factory();

        return [
            'run_id' => Run::factory(),
            'run_participant_id' => $participant,
            'character_id' => Character::factory(),
            'type' => RunEventType::Info,
            'level' => RunEvent::LEVEL_INFO,
            'message' => fake()->sentence(),
            'context' => null,
            'created_at' => now(),
        ];
    }

    /**
     * Attach the event to an existing participant, keeping run and character
     * consistent with it.
     */
    public function forParticipant(RunParticipant $participant): static
    {
        return $this->state(fn (array $attributes) => [
            'run_id' => $participant->run_id,
            'run_participant_id' => $participant->id,
            'character_id' => $participant->character_id,
        ]);
    }

    public function ofType(RunEventType $type, string $level = RunEvent::LEVEL_INFO): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'level' => $level,
        ]);
    }
}
