<?php

namespace App\Jobs;

use App\Game\Combat\StatsService;
use App\Game\Exceptions\GameException;
use App\Models\Character;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * One quick userstats.php read for one character, keeping the fleet grid's
 * rage/exp/level fresh outside runs. Dispatched in bulk after an RGA
 * connects and by the stale-stats scheduler; uniqueness stops a login
 * fan-out and the scheduler from stacking duplicate reads.
 */
class RefreshCharacterStatsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(public Character $character) {}

    public function uniqueId(): string
    {
        return (string) $this->character->id;
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function handle(): void
    {
        $character = $this->character->fresh();

        if ($character === null || ! $character->rga->hasSession()) {
            return;
        }

        try {
            StatsService::forCharacter($character)->refresh();
        } catch (GameException) {
            // A collision already flagged the RGA invalid; a parse failure
            // will heal on the next sweep. Either way the grid keeps its
            // last known values — never fail the queue over a stats read.
        }
    }
}
