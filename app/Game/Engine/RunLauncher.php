<?php

namespace App\Game\Engine;

use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Game\Exceptions\CharactersBusyException;
use App\Models\Character;
use App\Models\Run;
use App\Models\RunParticipant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Creates a Run with its participants and dispatches one queued worker per
 * character. Shared by the outwar:run-start command and the REST API so run
 * creation has a single code path.
 */
class RunLauncher
{
    public function __construct(private readonly RunDispatcher $dispatcher) {}

    /**
     * @param  Collection<int, Character>  $characters
     * @param  array<string, mixed>  $config  the mode's config array (MobRunConfig::toArray(), etc.)
     *
     * @throws CharactersBusyException when a character is already enrolled in an unfinished run
     */
    public function launch(
        RunMode $mode,
        Collection $characters,
        array $config,
        bool $castOnStart = false,
        bool $requireCircumspect = false,
        ?int $restartEveryMinutes = null,
        ?Carbon $startAt = null,
        ?User $user = null,
    ): Run {
        if ($startAt !== null && $startAt->isPast()) {
            $startAt = $startAt->addDay();
        }

        $this->guardAgainstBusyCharacters($characters);

        $run = Run::create([
            'user_id' => $user?->id,
            'mode' => $mode,
            'config' => $config,
            'cast_on_start' => $castOnStart,
            'require_circumspect' => $requireCircumspect,
            'status' => $startAt?->isFuture() ?? false ? RunStatus::Pending : RunStatus::Running,
            'restart_every_minutes' => $restartEveryMinutes,
            'start_at' => $startAt,
            'last_started_at' => $startAt ?? now(),
        ]);

        foreach ($characters as $character) {
            $participant = $run->participants()->create(['character_id' => $character->id]);
            $this->dispatcher->dispatch($participant, $startAt);
        }

        return $run;
    }

    /**
     * One character, one worker: reject enrollment while any earlier
     * participant of the character is still pending, live, or parked.
     *
     * @param  Collection<int, Character>  $characters
     */
    private function guardAgainstBusyCharacters(Collection $characters): void
    {
        $busyNames = RunParticipant::query()
            ->whereIn('character_id', $characters->pluck('id'))
            ->whereNotIn('status', [RunStatus::Stopped, RunStatus::Completed, RunStatus::Failed])
            ->with('character:id,name')
            ->get()
            ->pluck('character.name')
            ->unique()
            ->values();

        if ($busyNames->isNotEmpty()) {
            throw CharactersBusyException::forCharacters($busyNames->all());
        }
    }
}
