<?php

namespace App\Game\Data;

use App\Game\Enums\BattleOutcome;

/**
 * Parsed battle-result page (/attack/{id}/ or /plrattack/{id}/).
 */
final readonly class BattleResult
{
    /**
     * @param  array<string, int>  $statGains  stat name => amount (e.g. strength => 15)
     */
    public function __construct(
        public BattleOutcome $outcome,
        public ?string $attackerName,
        public ?string $defenderName,
        public ?int $expGained,
        public ?int $goldGained,
        public array $statGains,
        public ?string $dropName,
    ) {}
}
