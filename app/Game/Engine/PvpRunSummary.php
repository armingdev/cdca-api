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
        public int $skippedOnCooldown = 0,
        public ?int $nextFreeInMinutes = null,
    ) {}
}
