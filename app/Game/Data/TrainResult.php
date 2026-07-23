<?php

namespace App\Game\Data;

/**
 * Outcome of a train attempt. Guardrail rejections and unconfirmed trains are
 * failures with a human-readable reason.
 */
final readonly class TrainResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?int $newLevel = null,
        public ?int $skillPointsRemaining = null,
    ) {}

    public static function failure(string $message): self
    {
        return new self(success: false, message: $message);
    }
}
