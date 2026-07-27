<?php

namespace App\Game\Engine;

use App\Game\Enums\RunMode;
use App\Jobs\RunMobJob;
use App\Jobs\RunPvpJob;
use App\Jobs\RunQuestJob;
use App\Jobs\RunQuestListJob;
use App\Models\RunParticipant;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Str;

/**
 * Dispatches the right queued job for a participant based on its run mode.
 * Every dispatch mints a fresh token persisted on the participant; a job
 * whose token no longer matches (superseded by a later dispatch) no-ops,
 * which makes pause/resume and delayed starts idempotent.
 */
class RunDispatcher
{
    public function dispatch(RunParticipant $participant, ?\DateTimeInterface $delayUntil = null): PendingDispatch
    {
        $token = (string) Str::uuid();
        $participant->update(['dispatch_token' => $token]);

        $job = match ($participant->run->mode) {
            RunMode::Mob => new RunMobJob($participant, $token),
            RunMode::Quest => new RunQuestJob($participant, $token),
            RunMode::QuestList => new RunQuestListJob($participant, $token),
            RunMode::Pvp => new RunPvpJob($participant, $token),
        };

        return $delayUntil !== null ? dispatch($job->delay($delayUntil)) : dispatch($job);
    }
}
