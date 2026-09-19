<?php

namespace App\Game\Enums;

/**
 * Game events a run can save rage for (see RageReserveGate). Only events whose
 * schedule we actually read from the game belong here: the two Brawls come
 * from brawl_rounds. Gladiator and Envoy join once their end times have been
 * captured — adding a case and its window lookup is all that takes.
 */
enum RageReserveEvent: string
{
    case PvpBrawl = 'pvp-brawl';
    case FactionBrawl = 'faction-brawl';

    public function label(): string
    {
        return $this->brawlType()->label();
    }

    public function brawlType(): BrawlType
    {
        return match ($this) {
            self::PvpBrawl => BrawlType::Pvp,
            self::FactionBrawl => BrawlType::Faction,
        };
    }
}
