<?php

namespace App\Game\Data;

/**
 * Outcome of a junk-drop sweep over the regular backpack tab.
 */
final readonly class JunkDropSummary
{
    /**
     * @param  list<string>  $droppedNames
     */
    public function __construct(
        public int $scanned,
        public int $dropped,
        public array $droppedNames,
    ) {}
}
