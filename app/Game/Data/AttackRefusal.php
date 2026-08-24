<?php

namespace App\Game\Data;

use App\Game\Enums\AttackRefusalReason;

/**
 * Why a PvP attack did not happen. A refused attack answers 200 with no
 * redirect (a successful one 302s to /plrattack/{battleId}/), and states the
 * reason in the body.
 *
 * The cooldown refusal names the elapsed minutes, which makes the failure
 * self-correcting: we learn exactly when the target frees up rather than
 * guessing a full 60.
 */
final readonly class AttackRefusal
{
    public function __construct(
        public AttackRefusalReason $reason,
        public string $message,
        public ?int $minutesSinceLastAttack = null,
    ) {}

    /** Minutes until this target may be attacked again, when knowable. */
    public function retryInMinutes(): ?int
    {
        if ($this->reason !== AttackRefusalReason::Cooldown || $this->minutesSinceLastAttack === null) {
            return null;
        }

        return max(1, AttackRefusalReason::COOLDOWN_MINUTES - $this->minutesSinceLastAttack);
    }
}
