<?php

namespace App\Jobs;

use App\Game\Auth\CharacterSyncService;
use App\Game\Exceptions\GameException;
use App\Models\Rga;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Reads one RGA's character list from the game, so signing in to CDCA finds
 * the roster already current instead of waiting on a manual sync.
 *
 * It will not log the account in to do it. A game login can boot the session
 * the player is using in their own browser, and no background convenience is
 * worth that — a sessionless RGA is simply left alone until the player
 * connects it themselves.
 */
class SyncRgaCharactersJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public Rga $rga) {}

    public function uniqueId(): string
    {
        return (string) $this->rga->id;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(CharacterSyncService $sync): void
    {
        $rga = $this->rga->fresh();

        if ($rga === null || ! $rga->hasSession()) {
            return;
        }

        $debounce = (int) config('outwar.sync.characters_debounce_minutes', 360);

        // A character list barely changes; several logins in an afternoon
        // should not mean several sweeps of both game servers.
        if ($rga->characters_synced_at?->greaterThan(now()->subMinutes($debounce))) {
            return;
        }

        try {
            $sync->sync($rga);
        } catch (GameException) {
            // The session died, or a page changed shape. The roster keeps its
            // last known state and the next login tries again — never fail the
            // queue over a background convenience.
            return;
        }

        $rga->update(['characters_synced_at' => now()]);

        foreach ($rga->characters as $character) {
            RefreshCharacterStatsJob::dispatch($character);
        }
    }
}
