<?php

namespace App\Game\Auth;

use App\Game\Data\ChallengeClaimResult;
use App\Game\Data\ChallengeTokenStatus;
use App\Game\Http\GameClient;
use App\Game\Parsers\ChallengeTokenParser;
use App\Models\Character;
use App\Models\Rga;
use Carbon\CarbonImmutable;

/**
 * The daily challenge-token claim (3 a day per RGA, 30 held at most).
 *
 * The tokens belong to the RGA, but both endpoints answer "You must be
 * logged in" unless a character is selected — and GameClient::guard reads
 * that string as a dead session. So the calls go through one of the RGA's
 * own characters, never GameClient::forRga().
 */
class ChallengeTokenService
{
    /** Re-check an RGA at the cap this often: tokens spent in the game free room at any time. */
    public const int AT_CAP_RECHECK_MINUTES = 60;

    public function __construct(
        private readonly Rga $rga,
        private readonly GameClient $client,
        private readonly ChallengeTokenParser $parser,
    ) {}

    /**
     * Null when the RGA has no character of its own to select yet (a
     * trustee's character would answer for someone else's account).
     */
    public static function forRga(Rga $rga): ?self
    {
        $character = $rga->characters()->where('is_trustee', false)->orderBy('id')->first();

        if (! $character instanceof Character) {
            return null;
        }

        return new self($rga, GameClient::forCharacter($character), app(ChallengeTokenParser::class));
    }

    /**
     * Read the balance and timer from the game and keep them on the RGA.
     */
    public function status(): ChallengeTokenStatus
    {
        $status = $this->parser->parseStatus($this->client->get('ajax/challenge_status.php')->body());

        $this->rga->update([
            'challenge_tokens' => $status->tokens,
            'challenge_tokens_purchased' => $status->purchasedTokens,
            'challenge_claim_at' => $this->nextCheckAt($status),
            'challenge_synced_at' => now(),
        ]);

        return $status;
    }

    /**
     * Ask the game for today's tokens, then re-read the status so the stored
     * balance and timer reflect the claim (or the refusal).
     */
    public function claim(): ChallengeClaimResult
    {
        $result = $this->parser->parseClaim($this->client->get('ajax/challenge_claim.php')->body());

        if ($result->claimed) {
            $this->rga->update(['challenge_claimed_at' => now()]);
        }

        $this->status();

        return $result;
    }

    /**
     * When the scheduler should look at this RGA again: when the daily timer
     * runs out, or — if it is already out but the RGA is full — in an hour.
     */
    private function nextCheckAt(ChallengeTokenStatus $status): CarbonImmutable
    {
        if ($status->claimOpen()) {
            return CarbonImmutable::now()->addMinutes($status->atCap() ? self::AT_CAP_RECHECK_MINUTES : 0);
        }

        return CarbonImmutable::now()->addSeconds($status->secondsUntilClaim);
    }
}
