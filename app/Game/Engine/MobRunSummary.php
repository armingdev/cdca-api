<?php

namespace App\Game\Engine;

final readonly class MobRunSummary
{
    /**
     * @param  bool  $targetsRespawnPending  the pass stood in at least one room the
     *                                       targets are known to spawn in and found none of them alive, so it
     *                                       ran out of live mobs rather than out of mobs — callers may park and
     *                                       retry after a respawn.
     * @param  int|null  $rageShortfall  rage the next target costs beyond what the
     *                                   character holds, set only when the pass ended on RageInsufficient.
     */
    public function __construct(
        public int $wins,
        public int $losses,
        public int $errors,
        public string $stopReason,
        public RunEndReason $endReason,
        public bool $targetsRespawnPending = false,
        public ?int $rageShortfall = null,
    ) {}
}
