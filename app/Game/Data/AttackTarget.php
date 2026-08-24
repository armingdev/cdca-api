<?php

namespace App\Game\Data;

use App\Game\Enums\TargetAttackability;

/**
 * A PvP target as rendered by any list that can attack it (search results,
 * personal/crew hitlists). Every such list emits
 * `showAttackWindow(name, playerId, rage, hash[, redir])`, so a target
 * arrives already carrying the per-render hash the attack POST needs — no
 * profile fetch required.
 *
 * Sources without an attack icon (crew rosters, brawl standings) produce
 * targets with a null `hash`; those need one search hop before attacking.
 */
final readonly class AttackTarget
{
    public function __construct(
        public int $playerId,
        public string $name,
        public ?int $level = null,
        public TargetAttackability $attackability = TargetAttackability::Unknown,
        public ?string $hash = null,
        public ?int $rageCost = null,
    ) {}

    /** Whether this target can be attacked without minting a fresh hash first. */
    public function isReadyToAttack(): bool
    {
        return $this->hash !== null;
    }
}
