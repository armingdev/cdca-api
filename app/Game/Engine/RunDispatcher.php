<?php

namespace App\Game\Engine;

use App\Game\Enums\RunMode;
use App\Jobs\RunMobJob;
use App\Jobs\RunPvpJob;
use App\Jobs\RunQuestJob;
use App\Jobs\RunQuestListJob;
use App\Models\RunParticipant;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Dispatches the right queued job for a participant based on its run mode.
 */
class RunDispatcher
{
    public function dispatch(RunParticipant $participant, ?\DateTimeInterface $delayUntil = null): PendingDispatch
    {
        $job = match ($participant->run->mode) {
            RunMode::Mob => new RunMobJob($participant),
            RunMode::Quest => new RunQuestJob($participant),
            RunMode::QuestList => new RunQuestListJob($participant),
            RunMode::Pvp => new RunPvpJob($participant),
        };

        return $delayUntil !== null ? dispatch($job->delay($delayUntil)) : dispatch($job);
    }
}
