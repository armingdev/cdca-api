<?php

namespace App\Console\Commands;

use App\Game\Enums\CharacterActivity;
use App\Jobs\RefreshCharacterStatsJob;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:stats-refresh-stale {--minutes=15 : Staleness threshold} {--limit=100 : Max refresh jobs per tick}')]
#[Description('Queue stat refreshes for idle characters whose fleet-grid stats have gone stale (scheduled)')]
class StatsRefreshStaleCommand extends Command
{
    public function handle(): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

        // In-run characters refresh themselves after every attack; only idle
        // ones go stale. Sessionless RGAs are skipped — a refresh would just
        // fail (and a lazy login here could boot the user's browser session).
        $stale = Character::query()
            ->where('status', CharacterActivity::Idle)
            ->whereHas('rga', fn ($query) => $query->where('status', Rga::STATUS_ACTIVE)->whereNotNull('cookies'))
            ->where(fn ($query) => $query->whereNull('last_stats_at')->orWhere('last_stats_at', '<', $threshold))
            ->orderByRaw('last_stats_at asc nulls first')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $character) {
            RefreshCharacterStatsJob::dispatch($character);
        }

        $this->info("Queued {$stale->count()} stat refresh(es).");

        return self::SUCCESS;
    }
}
