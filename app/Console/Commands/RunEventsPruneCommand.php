<?php

namespace App\Console\Commands;

use App\Models\RunEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:run-events-prune {--days= : Override the configured retention window}')]
#[Description('Delete run log events past their retention window (scheduled)')]
class RunEventsPruneCommand extends Command
{
    /** Rows per delete so a large backlog never holds one long lock. */
    private const int CHUNK = 5000;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('outwar.run_events.retention_days', 30));
        $cutoff = now()->subDays($days);

        $deleted = 0;

        do {
            $batch = RunEvent::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->delete();

            $deleted += $batch;
        } while ($batch === self::CHUNK);

        $this->info("Pruned {$deleted} run event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
