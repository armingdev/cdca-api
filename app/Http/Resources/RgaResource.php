<?php

namespace App\Http\Resources;

use App\Models\Rga;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rga
 */
class RgaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'status' => $this->status,
            'has_session' => $this->hasSession(),
            'has_security_answer' => $this->security_answer !== null,
            'characters_count' => $this->whenCounted('characters'),
            'characters' => CharacterResource::collection($this->whenLoaded('characters')),
            'last_login_at' => $this->last_login_at,
            // Lets the client tell whether the roster it is showing came from
            // the background sync that runs after a CDCA login.
            'characters_synced_at' => $this->characters_synced_at,
            'auto_claim_tokens' => $this->auto_claim_tokens,
            // Last reading of the daily challenge tokens (null until first read).
            'challenge_tokens' => $this->challenge_tokens,
            'challenge_tokens_purchased' => $this->challenge_tokens_purchased,
            'challenge_claim_at' => $this->challenge_claim_at,
            'created_at' => $this->created_at,
        ];
    }
}
