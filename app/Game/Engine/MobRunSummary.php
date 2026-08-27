<?php

namespace App\Game\Engine;

final readonly class MobRunSummary
{
    /**
     * @param  bool  $sawDeadTargets  a target was seen dead in a visited room, so the
     *                                pass ran out of live mobs rather than out of mobs —
     *                                callers may park and retry after a respawn.
     */
    public function __construct(
        public int $wins,
        public int $losses,
        public int $errors,
        public string $stopReason,
        public RunEndReason $endReason,
        public bool $sawDeadTargets = false,
    ) {}
}
