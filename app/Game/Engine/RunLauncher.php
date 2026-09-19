<?php

namespace App\Game\Engine;

use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Game\Exceptions\CharactersBusyException;
use App\Game\Skills\SkillSelection;
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
    public function __construct(
        private readonly RunDispatcher $dispatcher,
        private readonly SkillSelection $selection,
    ) {}

    /**
     * A non-null $skillIds replaces every character's cast-on-start selection
     * before any worker is dispatched — the fleet-wide picker writes one
     * selection across ten characters in one request instead of ten PUTs, and
     * because it lands in the same per-character table, each of them keeps
     * that set as its own default afterwards. Null leaves every character's
     * existing selection alone.
     *
     * @param  Collection<int, Character>  $characters
     * @param  array<string, mixed>  $config  the mode's config array (MobRunConfig::toArray(), etc.)
     * @param  list<int>|null  $skillIds
     * @param  list<string>  $reserveRageFor  RageReserveEvent values the run parks ahead of
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
        ?array $skillIds = null,
        ?string $name = null,
        array $reserveRageFor = [],
        int $reserveRageHours = 12,
    ): Run {
        if ($startAt !== null && $startAt->isPast()) {
            $startAt = $startAt->addDay();
        }

        $this->guardAgainstBusyCharacters($characters);

        if ($skillIds !== null) {
            $skillIds = $this->applySelection($characters, $skillIds);

            // A selection is only meaningful if something casts it.
            $castOnStart = $castOnStart || $skillIds !== [];
        }

        $run = Run::create([
            'user_id' => $user?->id,
            'name' => $name,
            'mode' => $mode,
            'config' => $config,
            'cast_on_start' => $castOnStart,
            'require_circumspect' => $requireCircumspect,
            'skill_ids' => $skillIds,
            'reserve_rage_for' => $reserveRageFor === [] ? null : $reserveRageFor,
            'reserve_rage_hours' => $reserveRageHours,
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
     * Write one selection across the whole fleet.
     *
     * @param  Collection<int, Character>  $characters
     * @param  list<int>  $skillIds
     * @return list<int> the deduplicated ids stored on every character
     */
    private function applySelection(Collection $characters, array $skillIds): array
    {
        $stored = array_values(array_unique($skillIds));

        foreach ($characters as $character) {
            $this->selection->replaceFor($character, $stored);
        }

        return $stored;
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
