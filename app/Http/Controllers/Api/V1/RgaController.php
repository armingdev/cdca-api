<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Auth\CharacterSyncService;
use App\Game\Auth\LoginService;
use App\Game\Exceptions\GameException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttachRgaSessionRequest;
use App\Http\Requests\StoreRgaRequest;
use App\Http\Resources\CharacterResource;
use App\Http\Resources\RgaResource;
use App\Http\Resources\RgaSessionResource;
use App\Jobs\RefreshCharacterStatsJob;
use App\Models\Rga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RgaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RgaResource::collection(
            $request->user()->rgas()->withCount('characters')->latest()->get()
        );
    }

    public function store(StoreRgaRequest $request): JsonResponse
    {
        $rga = $request->user()->rgas()->create($request->validated());

        return RgaResource::make($rga)->response()->setStatusCode(201);
    }

    public function show(Rga $rga): RgaResource
    {
        Gate::authorize('view', $rga);

        return RgaResource::make($rga->loadCount('characters'));
    }

    public function destroy(Rga $rga): JsonResponse
    {
        Gate::authorize('delete', $rga);

        $rga->delete();

        return response()->json(['message' => 'RGA deleted.']);
    }

    /**
     * Log the RGA in to the game and capture its session cookies. Kicks off
     * a stat refresh for every known character so the fleet grid is live
     * right after connecting.
     */
    public function login(Rga $rga, LoginService $loginService): JsonResponse
    {
        Gate::authorize('update', $rga);

        try {
            $loginService->login($rga);
        } catch (GameException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->queueStatsRefresh($rga);

        return RgaResource::make($rga->fresh())->response();
    }

    /**
     * Adopt a session pasted from the user's browser (rg_sess_id cookie) —
     * shares the browser's session instead of booting it with a fresh login.
     */
    public function attachSession(AttachRgaSessionRequest $request, Rga $rga, LoginService $loginService): JsonResponse
    {
        Gate::authorize('update', $rga);

        try {
            $loginService->attachSession(
                $rga,
                $request->validated('rg_sess_id'),
                $request->validated('token'),
                $request->validated('cuserid2'),
            );
        } catch (GameException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->queueStatsRefresh($rga);

        return RgaResource::make($rga->fresh())->response();
    }

    /**
     * Reveal the stored session cookies so the user can reuse them in a
     * browser or another tool.
     */
    public function showSession(Rga $rga): RgaSessionResource|JsonResponse
    {
        Gate::authorize('view', $rga);

        if (empty($rga->cookies['rg_sess_id'] ?? null)) {
            return response()->json(['message' => 'No session captured for this RGA.'], 404);
        }

        return RgaSessionResource::make($rga);
    }

    /**
     * Discover and upsert all characters on the RGA (both servers).
     */
    public function syncCharacters(Rga $rga, CharacterSyncService $syncService, LoginService $loginService): AnonymousResourceCollection|JsonResponse
    {
        Gate::authorize('update', $rga);

        try {
            if (! $rga->hasSession()) {
                $loginService->login($rga);
            }

            $characters = $syncService->sync($rga);
        } catch (GameException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->queueStatsRefresh($rga);

        return CharacterResource::collection($characters);
    }

    /**
     * Queue a stat refresh for every character on the RGA (202 — the grid
     * fills in as the jobs land).
     */
    public function refreshStats(Rga $rga): JsonResponse
    {
        Gate::authorize('update', $rga);

        if (! $rga->hasSession()) {
            return response()->json(['message' => 'No active session — log the RGA in first.'], 422);
        }

        $queued = $this->queueStatsRefresh($rga);

        return response()->json(['message' => "Queued {$queued} stat refresh(es)."], 202);
    }

    private function queueStatsRefresh(Rga $rga): int
    {
        $characters = $rga->characters()->get();

        foreach ($characters as $character) {
            RefreshCharacterStatsJob::dispatch($character);
        }

        return $characters->count();
    }
}
