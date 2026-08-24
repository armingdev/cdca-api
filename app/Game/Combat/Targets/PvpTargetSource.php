<?php

namespace App\Game\Combat\Targets;

use App\Game\Data\AttackTarget;

/**
 * Where a PvP run gets its targets. The five modes differ only in this, so
 * the runner depends on the interface and stays mode-agnostic.
 *
 * Implementations return targets in the order they should be attacked.
 * Some sources carry a per-render attack hash (anything rendering an attack
 * icon), others do not — see AttackTarget::isReadyToAttack().
 */
interface PvpTargetSource
{
    /**
     * @return list<AttackTarget>
     */
    public function targets(): array;

    /** Human-readable description of this source, for run logs. */
    public function label(): string;
}
