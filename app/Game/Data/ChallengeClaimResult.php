<?php

namespace App\Game\Data;

/**
 * The game's answer to ajax/challenge_claim.php: `{result, message}`.
 */
final readonly class ChallengeClaimResult
{
    public function __construct(
        public bool $claimed,
        public string $message,
    ) {}
}
