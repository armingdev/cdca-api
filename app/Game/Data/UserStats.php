<?php

namespace App\Game\Data;

/**
 * Parsed userstats.php response — the canonical rage/exp/level refresh.
 */
final readonly class UserStats
{
    public function __construct(
        public int $exp,
        public int $rage,
        public int $level,
    ) {}
}
