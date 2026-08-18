<?php

namespace App\Game\Data;

/**
 * Outcome of reading one character's teleport anchors: what it can use now,
 * how much of that was new to the global catalog, and what it lost since the
 * last sync.
 */
final readonly class TeleportSyncResult
{
    public function __construct(
        public int $itemAnchors,
        public int $skillAnchors,
        public int $discovered,
        public int $unavailable,
        /** Anchors whose landing room is still unknown — discovery targets. */
        public int $withoutDestination,
    ) {}

    public function total(): int
    {
        return $this->itemAnchors + $this->skillAnchors;
    }
}
