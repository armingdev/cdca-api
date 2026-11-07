<?php

namespace App\Game\Data;

/**
 * An RGA's challenge-token reading from ajax/challenge_status.php.
 */
final readonly class ChallengeTokenStatus
{
    /** The game refuses to claim past this many held (purchased tokens do not count). */
    public const int MAX_TOKENS = 30;

    public function __construct(
        public int $tokens,
        public int $purchasedTokens,
        /** Seconds until the daily claim opens; zero or negative = open now. */
        public int $secondsUntilClaim,
    ) {}

    public function claimOpen(): bool
    {
        return $this->secondsUntilClaim <= 0;
    }

    public function atCap(): bool
    {
        return $this->tokens >= self::MAX_TOKENS;
    }

    /**
     * Worth asking the game for: the daily timer is up and there is room.
     */
    public function claimable(): bool
    {
        return $this->claimOpen() && ! $this->atCap();
    }
}
