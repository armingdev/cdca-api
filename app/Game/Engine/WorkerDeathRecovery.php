<?php

namespace App\Game\Engine;

use App\Game\Enums\RunEventType;
use App\Game\Enums\RunStatus;
use App\Models\RunEvent;
use App\Models\RunParticipant;
use Illuminate\Support\Facades\Cache;

/**
 * What to do with a participant whose worker died under it — killed by the
 * OOM killer, a hard restart, a lost Redis — without running any of the job's
 * own cleanup. Nothing is wrong with the run, so it is re-driven rather than
 * failed: a stop or pause the user already asked for is honoured, and a
 * running participant parks briefly for the resume scheduler.
 *
 * Bounded like every other park: deaths in a row are tallied in `progress`
 * (RunJob resets the tally on a clean engine return), so a run that reliably
 * kills its worker fails loudly instead of looping forever.
 *
 * Safe against a worker that was only slow, not dead: the re-dispatch mints a
 * new dispatch token, and RunJob refuses to touch a participant whose token is
 * no longer its own.
 */
class WorkerDeathRecovery
{
    /** Consecutive worker deaths tolerated before the participant fails. */
    public const int MAX_WORKER_DEATHS = 3;

    /** Long enough for replacement workers to be up. */
    private const int RESUME_SECONDS = 60;

    public function recover(RunParticipant $participant, string $cause): void
    {
        if (! $participant->status->isInFlight()) {
            return;
        }

        // The dead worker still "holds" the one-worker-per-character lock until
        // its two-hour TTL; nothing is left to protect, so free it for the resume.
        Cache::lock("character-run:{$participant->character_id}")->forceRelease();

        match ($participant->status) {
            RunStatus::Stopping => $participant->transition(RunStatus::Stopped, 'Stopped.'),
            RunStatus::Pausing => $participant->transition(RunStatus::Paused, 'Paused.'),
            default => $this->parkOrFail($participant, $cause),
        };

        $participant->loadMissing('run')->run->refreshStatus();
    }

    private function parkOrFail(RunParticipant $participant, string $cause): void
    {
        $deaths = (int) ($participant->progress['worker_deaths'] ?? 0) + 1;
        $recorder = new RunEventRecorder($participant);

        if ($deaths > self::MAX_WORKER_DEATHS) {
            $message = "{$cause} Gave up after ".self::MAX_WORKER_DEATHS.' worker deaths in a row.';

            $participant->transition(RunStatus::Failed, $message, progress: ['worker_deaths' => $deaths]);
            $recorder->record(RunEventType::Failed, $message, ['worker_deaths' => $deaths], RunEvent::LEVEL_ERROR);

            return;
        }

        $resumeAt = now()->addSeconds(self::RESUME_SECONDS);
        $message = "{$cause} Resuming shortly.";

        $participant->transition(RunStatus::Waiting, $message, $resumeAt, ['worker_deaths' => $deaths]);
        $recorder->record(
            RunEventType::Parked,
            $message,
            ['status' => RunStatus::Waiting->value, 'resume_at' => $resumeAt->toIso8601String(), 'worker_deaths' => $deaths],
            RunEvent::LEVEL_WARNING,
        );
    }
}
