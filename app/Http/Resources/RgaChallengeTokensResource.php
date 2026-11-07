<?php

namespace App\Http\Resources;

use App\Game\Data\ChallengeTokenStatus;
use App\Models\Rga;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An RGA's challenge-token balance as last read from the game.
 *
 * @mixin Rga
 */
class RgaChallengeTokensResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rga_id' => $this->id,
            'auto_claim_tokens' => $this->auto_claim_tokens,
            'tokens' => $this->challenge_tokens,
            'max_tokens' => ChallengeTokenStatus::MAX_TOKENS,
            'purchased_tokens' => $this->challenge_tokens_purchased,
            // The next moment a claim is worth trying: the daily timer, or an
            // hourly re-check while the RGA sits at the cap.
            'next_claim_at' => $this->challenge_claim_at,
            'claimed_at' => $this->challenge_claimed_at,
            'synced_at' => $this->challenge_synced_at,
        ];
    }
}
