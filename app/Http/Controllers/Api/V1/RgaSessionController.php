<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Auth\LoginService;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexRgaSessionsRequest;
use App\Http\Resources\RgaSessionResource;
use App\Models\Rga;
use Illuminate\Http\JsonResponse;

/**
 * Every live session the user holds, in one response — the "copy all
 * sessions" button. Only the caller's own RGAs; a crew leader reads shared
 * ones through the crew-group endpoint instead.
 */
class RgaSessionController extends Controller
{
    public function index(IndexRgaSessionsRequest $request, LoginService $loginService): JsonResponse
    {
        $verify = $request->boolean('verify');

        $rgas = $request->user()->rgas()
            ->where('status', Rga::STATUS_ACTIVE)
            ->whereNotNull('cookies')
            ->withCount('characters')
            ->orderBy('username')
            ->get()
            ->filter(fn (Rga $rga): bool => ! empty($rga->cookies['rg_sess_id'] ?? null));

        $sessions = $rgas->map(function (Rga $rga) use ($request, $verify, $loginService): array {
            // probeSession() may flip the status, so it runs before the row
            // is rendered: the response shows what the game just said.
            $alive = $verify ? $loginService->probeSession($rga) : null;

            return [
                ...RgaSessionResource::make($rga)->resolve($request),
                'characters_count' => $rga->characters_count,
                'alive' => $alive,
            ];
        })->values();

        return response()->json(['data' => $sessions]);
    }
}
