<?php

namespace App\Game\Engine;

use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\Run;
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

        $run = Run::create([
            'user_id' => $user?->id,
            'mode' => $mode,
            'config' => $config,
            'cast_on_start' => $castOnStart,
            'require_circumspect' => $requireCircumspect,
            'status' => RunStatus::Running,
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
}
