<?php

namespace App\Console\Commands;

use App\Game\Engine\WorkerDeathRecovery;
use App\Game\Enums\RunStatus;
use App\Models\RunParticipant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('outwar:runs-recover-stalled')]
#[Description('Re-drive participants whose worker died mid-run and stopped sending heartbeats (scheduled every minute)')]
class RunsRecoverStalledCommand extends Command
{
    public function handle(WorkerDeathRecovery $recovery): int
    {
        $cutoff = now()->subSeconds((int) config('outwar.runs.stalled_after_seconds'));

        // Pending is deliberately absent: a participant queued behind a full
        // set of workers is waiting its turn, not orphaned.
        $stalled = RunParticipant::query()
            ->whereIn('status', [RunStatus::Running, RunStatus::Stopping, RunStatus::Pausing])
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('heartbeat_at', '<', $cutoff)
                    // Picked up before heartbeats existed, or died before its first one.
                    ->orWhere(fn (Builder $query) => $query->whereNull('heartbeat_at')->where('updated_at', '<', $cutoff));
            })
            ->with('run')
            ->get();

        foreach ($stalled as $participant) {
            $recovery->recover($participant, 'The worker driving this run stopped responding.');

            $this->warn("Recovered participant #{$participant->id} of run #{$participant->run_id} ({$participant->status->value}).");
        }

        if ($stalled->isEmpty()) {
            $this->line('No stalled participants.');
        }

        return self::SUCCESS;
    }
}
