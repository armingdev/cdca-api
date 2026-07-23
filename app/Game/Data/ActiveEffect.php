<?php

namespace App\Game\Data;

/**
 * One entry from the Current Effects panel (buffs on this character, castBy
 * set — buffs can come from other players) or the Cast Skills panel (skills
 * this character cast, castOn set).
 */
final readonly class ActiveEffect
{
    public function __construct(
        public string $name,
        public int $level,
        public int $minutesLeft,
        public ?string $castBy = null,
        public ?string $castOn = null,
    ) {}
}
