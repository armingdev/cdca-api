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
        /**
         * Experience taken off the defender on a PvP win. This is what the
         * weekly Open PvP Tournament ranks players by, and it is not the same
         * number as `expGained` in principle — capture both.
         */
        public ?int $expStripped,
        public ?int $goldGained,
        public array $statGains,
        public ?string $dropName,
        /**
         * The raw `battle_result` string. Kept so an outcome we cannot
         * classify can be logged verbatim and turned into a rule, rather than
         * disappearing as an "unknown".
         */
        public string $rawBattleResult = '',
    ) {}
}
