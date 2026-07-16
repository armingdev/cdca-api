<?php

namespace App\Game\Engine;

final readonly class MobRunSummary
{
    public function __construct(
        public int $wins,
        public int $losses,
        public int $errors,
        public string $stopReason,
        public bool $externallyStopped = false,
    ) {}
}
