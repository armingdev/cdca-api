<?php

namespace App\Console\Commands;

use App\Game\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:run-stop {run : Run id}')]
#[Description('Request a graceful stop: every worker exits at its next loop iteration')]
class RunStopCommand extends Command
{
    public function handle(): int
    {
        $run = Run::find((int) $this->argument('run'));

        if ($run === null) {
            $this->error('Run not found.');

            return self::FAILURE;
        }

        $run->update(['status' => RunStatus::Stopping, 'restart_every_minutes' => null]);

        $flagged = $run->participants()
            ->whereIn('status', [RunStatus::Pending, RunStatus::Running])
            ->update(['status' => RunStatus::Stopping]);

        $run->refreshStatus();

        $this->info("Stop requested for run #{$run->id} ({$flagged} active participant(s) flagged).");

        return self::SUCCESS;
    }
}
