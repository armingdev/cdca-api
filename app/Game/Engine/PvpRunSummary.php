<?php

namespace App\Game\Engine;

final readonly class PvpRunSummary
{
    public function __construct(
        public bool $completed,
        public int $attacks,
        public string $stopReason,
        public RunEndReason $endReason,
        public int $wins = 0,
        public int $losses = 0,
        /** Attacks that happened but whose result page we could not classify. */
        public int $unknown = 0,
        public int $skippedOnCooldown = 0,
        public ?int $nextFreeInMinutes = null,
    ) {}
}
