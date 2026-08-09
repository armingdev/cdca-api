<?php

namespace App\Game\Data;

/**
 * Outcome of one auto-equip pass over the regular backpack tab.
 */
final readonly class GearOptimizeSummary
{
    /**
     * @param  list<string>  $equippedNames
     */
    public function __construct(
        public int $scanned,
        public int $equipped,
        public array $equippedNames,
    ) {}
}
