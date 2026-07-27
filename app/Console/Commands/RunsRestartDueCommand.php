<?php

namespace App\Console\Commands;

use App\Game\Engine\RunDispatcher;
use App\Game\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:runs-restart-due')]
#[Description('Re-dispatch finished runs whose restart interval has elapsed (scheduled every minute)')]
class RunsRestartDueCommand extends Command
{
    public function handle(RunDispatcher $dispatcher): int
    {
        // Only fully completed runs restart: stopped runs were disarmed by the
        // stop itself, and waiting/paused runs are mid-cycle, not finished.
        $due = Run::query()
            ->whereNotNull('restart_every_minutes')
            ->where('status', RunStatus::Completed)
            ->whereNotNull('last_started_at')
            ->whereRaw('last_started_at + make_interval(mins => restart_every_minutes) <= now()')
            ->get();

        foreach ($due as $run) {
            $run->update(['status' => RunStatus::Running, 'last_started_at' => now()]);

            foreach ($run->participants as $participant) {
                // Wins stay cumulative across restarts; progress resets so
                // the new cycle is a clean pass 1.
                $participant->update([
                    'status' => RunStatus::Pending,
                    'progress' => null,
                    'resume_at' => null,
                    'started_at' => null,
                    'finished_at' => null,
                ]);

                $dispatcher->dispatch($participant);
            }

            $this->info("Restarted run #{$run->id}.");
        }

        if ($due->isEmpty()) {
            $this->line('No runs due for restart.');
        }

        return self::SUCCESS;
    }
}
