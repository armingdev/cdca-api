<?php

namespace App\Console\Commands;

use App\Game\Engine\RunDispatcher;
use App\Game\Enums\RunStatus;
use App\Models\RunParticipant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:runs-resume-due')]
#[Description('Re-dispatch waiting participants whose resume time has arrived (scheduled every minute)')]
class RunsResumeDueCommand extends Command
{
    public function handle(RunDispatcher $dispatcher): int
    {
        $due = RunParticipant::query()
            ->where('status', RunStatus::Waiting)
            ->where('resume_at', '<=', now())
            ->whereHas('run', fn ($query) => $query->whereIn('status', [RunStatus::Running, RunStatus::Waiting]))
            ->with('run')
            ->get();

        foreach ($due as $participant) {
            $participant->transition(RunStatus::Pending, 'Resuming…');
            $participant->run->update(['status' => RunStatus::Running]);
            $dispatcher->dispatch($participant);

            $this->info("Resumed participant #{$participant->id} of run #{$participant->run_id}.");
        }

        if ($due->isEmpty()) {
            $this->line('No participants due for resume.');
        }

        return self::SUCCESS;
    }
}
