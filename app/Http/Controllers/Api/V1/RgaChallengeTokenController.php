<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Auth\ChallengeTokenService;
use App\Http\Controllers\Controller;
use App\Http\Resources\RgaChallengeTokensResource;
use App\Models\Rga;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Daily challenge tokens of one RGA: a live reading, and a manual claim
 * (the scheduler claims on its own while auto_claim_tokens is on).
 */
class RgaChallengeTokenController extends Controller
{
    public function show(Rga $rga): RgaChallengeTokensResource|JsonResponse
    {
        Gate::authorize('view', $rga);

        $service = $this->serviceFor($rga);

        if ($service instanceof JsonResponse) {
            return $service;
        }

        $service->status();

        return RgaChallengeTokensResource::make($rga->fresh());
    }

    public function claim(Rga $rga): JsonResponse
    {
        Gate::authorize('update', $rga);

        $service = $this->serviceFor($rga);

        if ($service instanceof JsonResponse) {
            return $service;
        }

        $status = $service->status();

        if (! $status->claimOpen()) {
            return response()->json([
                'message' => 'Today\'s tokens are already claimed — the next claim opens at '.$rga->fresh()->challenge_claim_at?->toIso8601String().'.',
            ], 422);
        }

        $result = $service->claim();

        return RgaChallengeTokensResource::make($rga->fresh())
            ->additional(['message' => $result->message])
            ->response()
            ->setStatusCode($result->claimed ? 200 : 422);
    }

    private function serviceFor(Rga $rga): ChallengeTokenService|JsonResponse
    {
        if (! $rga->hasSession()) {
            return response()->json(['message' => 'No active session — log the RGA in first.'], 422);
        }

        return ChallengeTokenService::forRga($rga)
            ?? response()->json(['message' => 'Sync the RGA\'s characters first — the game needs one selected to read tokens.'], 422);
    }
}
