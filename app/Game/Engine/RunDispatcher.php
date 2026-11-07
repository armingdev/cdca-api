<?php

namespace App\Game\Engine;

use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Jobs\RunBrawlJob;
use App\Jobs\RunJob;
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

        return $this->push($participant, $token, $delayUntil);
    }

    /**
     * Move a participant that is still waiting for a worker to its run's
     * current priority queue. The token swap only lands while the participant
     * is Pending under the token it was queued with — a worker that already
     * claimed it (RunJob::handle) wins, and nothing is re-queued. The old
     * queued job then finds its token superseded and no-ops.
     */
    public function requeue(RunParticipant $participant, ?\DateTimeInterface $delayUntil = null): bool
    {
        $token = (string) Str::uuid();

        $swapped = RunParticipant::whereKey($participant->id)
            ->where('status', RunStatus::Pending)
            ->where('dispatch_token', $participant->dispatch_token)
            ->update(['dispatch_token' => $token]);

        if ($swapped === 0) {
            return false;
        }

        $participant->forceFill(['dispatch_token' => $token])->syncOriginalAttribute('dispatch_token');
        $this->push($participant, $token, $delayUntil);

        return true;
    }

    private function push(RunParticipant $participant, string $token, ?\DateTimeInterface $delayUntil): PendingDispatch
    {
        $job = $this->jobFor($participant, $token);

        return $delayUntil !== null ? dispatch($job->delay($delayUntil)) : dispatch($job);
    }

    private function jobFor(RunParticipant $participant, string $token): RunJob
    {
        return match ($participant->run->mode) {
            RunMode::Mob => new RunMobJob($participant, $token),
            RunMode::Quest => new RunQuestJob($participant, $token),
            RunMode::QuestList => new RunQuestListJob($participant, $token),
            RunMode::PvpBrawl, RunMode::PvpFactionBrawl => new RunBrawlJob($participant, $token),
            // The list-based PvP modes differ only in their target source.
            RunMode::PvpAttackList,
            RunMode::PvpCrewHitlist,
            RunMode::PvpCrewMembers => new RunPvpJob($participant, $token),
        };
    }
}
