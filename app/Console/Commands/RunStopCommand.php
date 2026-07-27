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

        $run->requestStop();

        $flagged = $run->participants()->where('status', RunStatus::Stopping)->count();

        $this->info("Stop requested for run #{$run->id} ({$flagged} active participant(s) flagged).");

        return self::SUCCESS;
    }
}
