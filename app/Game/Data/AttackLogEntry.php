<?php

namespace App\Game\Data;

use App\Game\Enums\BattleOutcome;
use Carbon\CarbonImmutable;

/**
 * One row of `/attacklog?mode=out`. This is the authoritative record of when
 * we last hit a given player, so it — not local history — seeds the
 * per-target 60-minute cooldown on run start.
 */
final readonly class AttackLogEntry
{
    public function __construct(
        public int $opponentPlayerId,
        public string $opponentName,
        public CarbonImmutable $occurredAt,
        public BattleOutcome $outcome,
        public ?int $battleId = null,
        public string $message = '',
    ) {}
}
