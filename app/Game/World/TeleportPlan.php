<?php

namespace App\Game\World;

use App\Models\TeleportAnchor;

/**
 * How to get from A to B: optionally one teleport, then a walk. `walkPath` is
 * a RoomGraph path (inclusive of both ends) starting at the jump's landing
 * room when there is a jump, otherwise at the origin.
 */
final readonly class TeleportPlan
{
    /**
     * @param  list<int>  $walkPath
     */
    public function __construct(
        public ?TeleportAnchor $anchor,
        public array $walkPath,
        public bool $useHomeTavern = false,
    ) {}

    public function usesTeleport(): bool
    {
        return $this->anchor !== null || $this->useHomeTavern;
    }

    /**
     * Rooms to walk through after any jump (the landing room does not count).
     */
    public function steps(): int
    {
        return max(0, count($this->walkPath) - 1);
    }

    /**
     * Total requests the plan costs: one per walk step, plus the jump.
     */
    public function cost(): int
    {
        return $this->steps() + ($this->usesTeleport() ? 1 : 0);
    }
}
