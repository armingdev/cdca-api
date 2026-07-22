<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Auth\CharacterSyncService;
use App\Game\Auth\LoginService;
use App\Game\Exceptions\GameException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRgaRequest;
use App\Http\Resources\CharacterResource;
use App\Http\Resources\RgaResource;
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
     * Log the RGA in to the game and capture its session cookies.
     */
    public function login(Rga $rga, LoginService $loginService): JsonResponse
    {
        Gate::authorize('update', $rga);

        try {
            $loginService->login($rga);
        } catch (GameException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return RgaResource::make($rga->fresh())->response();
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

        return CharacterResource::collection($characters);
    }
}
