<?php

namespace App\Console\Commands;

use App\Models\Run;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:run-status {run? : Run id (omit to list recent runs)}')]
#[Description('Show run progress: per-character status, tallies, and last activity')]
class RunStatusCommand extends Command
{
    public function handle(): int
    {
        if ($this->argument('run') === null) {
            $this->table(
                ['ID', 'Mode', 'Status', 'Targets', 'Restart', 'Started'],
                Run::latest()->limit(15)->get()->map(fn (Run $run) => [
                    $run->id,
                    $run->mode->value,
                    $run->status->value,
                    implode(', ', $run->config['mob_names'] ?? []),
                    $run->restart_every_minutes !== null ? "every {$run->restart_every_minutes}m" : '—',
                    $run->last_started_at?->diffForHumans() ?? '—',
                ]),
            );

            return self::SUCCESS;
        }

        $run = Run::with('participants.character')->find((int) $this->argument('run'));

        if ($run === null) {
            $this->error('Run not found.');

            return self::FAILURE;
        }

        $this->info("Run #{$run->id} [{$run->mode->value}] — {$run->status->value}");
        $this->table(
            ['Character', 'Status', 'W', 'L', 'E', 'Last activity'],
            $run->participants->map(fn ($participant) => [
                $participant->character->name,
                $participant->status->value,
                $participant->wins,
                $participant->losses,
                $participant->errors,
                $participant->last_activity ?? '—',
            ]),
        );

        return self::SUCCESS;
    }
}
