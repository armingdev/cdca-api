<?php

namespace App\Game\Data;

/**
 * One player from a playersearch.php result. The row's showAttackWindow call
 * already carries the per-render attack hash, so a target can be attacked
 * straight from search without opening its profile.
 */
final readonly class PlayerSearchResult
{
    public function __construct(
        public string $name,
        public int $playerId,
        public int $defaultRage,
        public string $hash,
        public ?int $level,
    ) {}
}
