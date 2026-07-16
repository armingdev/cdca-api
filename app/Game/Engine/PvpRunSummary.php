<?php

namespace App\Game\Engine;

final readonly class PvpRunSummary
{
    public function __construct(
        public bool $completed,
        public int $attacks,
        public string $stopReason,
        public bool $externallyStopped = false,
    ) {}
}
