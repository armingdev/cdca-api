<?php

namespace App\Game\Combat;

use App\Game\Data\MobSighting;
use App\Models\Character;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps our own characters off each other's mobs. Mobs are shared world
 * state: when 100+ characters play one list, several pick the same spawn,
 * one kills it, and the rest get "Invalid mob!" (150 failed jobs in prod by
 * Nov 2026). A character claims a spawn before attacking it and releases it
 * right after; a claimed spawn is somebody else's turn.
 *
 * Keyed by the room blob's spawnId — one spawn slot, the same for every
 * player — not the per-load encid. One instance per runner: it holds at most
 * one claim, and taking a new one drops the old.
 */
class MobClaims
{
    /**
     * Seconds a claim outlives an owner that never releases it (a killed
     * worker). One attack round trip, buffs included, is a few seconds.
     */
    private const int HOLD_SECONDS = 15;

    private ?Lock $held = null;

    public function __construct(private readonly int $serverId) {}

    public static function forCharacter(Character $character): self
    {
        return new self($character->server_id);
    }

    /**
     * Claim the spawn for this character. False while another of our
     * characters holds it. A sighting without a spawnId cannot be keyed and
     * is attacked as before.
     */
    public function claim(MobSighting $sighting, int $roomId): bool
    {
        $this->release();

        if ($sighting->spawnId <= 0) {
            return true;
        }

        $lock = Cache::lock("mob-claim:{$this->serverId}:{$roomId}:{$sighting->spawnId}", self::HOLD_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        $this->held = $lock;

        return true;
    }

    public function release(): void
    {
        $this->held?->release();
        $this->held = null;
    }
}
