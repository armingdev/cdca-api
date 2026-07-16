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
        $due = Run::query()
            ->whereNotNull('restart_every_minutes')
            ->whereIn('status', [RunStatus::Completed, RunStatus::Stopped])
            ->get()
            ->filter(fn (Run $run) => $run->status === RunStatus::Completed
                && $run->last_started_at !== null
                && $run->last_started_at->addMinutes($run->restart_every_minutes)->isPast());

        foreach ($due as $run) {
            $run->update(['status' => RunStatus::Running, 'last_started_at' => now()]);

            foreach ($run->participants as $participant) {
                $participant->update([
                    'status' => RunStatus::Pending,
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
