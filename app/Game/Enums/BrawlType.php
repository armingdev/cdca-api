<?php

namespace App\Game\Enums;

/**
 * The two fortnightly Brawl events, both served by `/closedpvp`.
 *
 * Round ids increment per event and interleave by type — PvP Brawl took the
 * odd ids (…175, 177), Faction Brawl the even ones (…176, 178).
 */
enum BrawlType: int
{
    case Pvp = 0;
    case Faction = 1;

    public function label(): string
    {
        return match ($this) {
            self::Pvp => 'PvP Brawl',
            self::Faction => 'Faction Brawl',
        };
    }

    /** Minimum character level to enter. */
    public function requiredLevel(): int
    {
        return match ($this) {
            self::Pvp => 85,
            self::Faction => 95,
        };
    }

    public function pageUrl(): string
    {
        return $this === self::Pvp ? 'closedpvp' : 'closedpvp?type=1';
    }

    public function enterUrl(): string
    {
        return 'closedpvp?enter=1&type='.$this->value;
    }
}
