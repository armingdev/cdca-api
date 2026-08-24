<?php

namespace App\Game\Data;

/**
 * One row of a brawl's standings table: `Rank | Character | Wins | Damage`.
 *
 * What `Damage` measures is not yet captured — the 2026-08-22 capture caught
 * the event dormant, with every registrant on 0/0.
 */
final readonly class BrawlStanding
{
    public function __construct(
        public int $rank,
        public int $playerId,
        public string $name,
        public int $wins = 0,
        public int $damage = 0,
    ) {}

    public function toAttackTarget(): AttackTarget
    {
        return new AttackTarget(
            playerId: $this->playerId,
            name: $this->name,
        );
    }
}
