<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
use App\Game\World\TeleportPlanner;
use App\Game\World\TeleportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetHomeTavernRequest;
use App\Http\Requests\TeleportRequest;
use App\Http\Resources\CharacterTeleportAnchorResource;
use App\Models\Character;
use App\Models\TeleportAnchor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A character's teleport anchors: what it can jump with, refreshing that from
 * the game, and travelling (jump + walk) to a room.
 */
class CharacterTeleportController extends Controller
{
    public function index(Character $character): AnonymousResourceCollection
    {
        Gate::authorize('view', $character);

        return CharacterTeleportAnchorResource::collection(
            $character->teleportAnchors()->with('anchor.room')->get()
        );
    }

    /**
     * Re-read the key tab (and the Teleport skill's destinations) from the
     * game — availability follows the character's level and quest progress.
     */
    public function sync(Character $character): JsonResponse
    {
        Gate::authorize('update', $character);

        $result = TeleportService::forCharacter($character)->syncAnchors();

        return response()->json([
            'message' => "{$result->total()} teleport anchor(s) available.",
            'item_anchors' => $result->itemAnchors,
            'skill_anchors' => $result->skillAnchors,
            'discovered' => $result->discovered,
            'unavailable' => $result->unavailable,
            'without_destination' => $result->withoutDestination,
            'anchors' => CharacterTeleportAnchorResource::collection(
                $character->teleportAnchors()->with('anchor.room')->get()
            ),
        ]);
    }

    /**
     * Travel: jump with a named anchor, return to the home tavern, or let the
     * planner pick the cheapest jump-then-walk route to a room.
     */
    public function store(TeleportRequest $request, Character $character): JsonResponse
    {
        Gate::authorize('update', $character);

        $service = TeleportService::forCharacter($character);

        if ($request->filled('anchor_id')) {
            $blob = $service->jump(TeleportAnchor::findOrFail($request->integer('anchor_id')));

            return $this->arrived($blob->curRoom, $blob->name, jumped: true, steps: 0);
        }

        if ($request->boolean('home_tavern') && ! $request->filled('room_id')) {
            $blob = $service->toHomeTavern();

            return $this->arrived($blob->curRoom, $blob->name, jumped: true, steps: 0);
        }

        return $this->travelTo($character, $service, $request->integer('room_id'));
    }

    /**
     * Set the free teleport=1 anchor to a tavern the character has reached.
     */
    public function setHomeTavern(SetHomeTavernRequest $request, Character $character): JsonResponse
    {
        Gate::authorize('update', $character);

        $roomId = $request->integer('room_id');

        TeleportService::forCharacter($character)->setHomeTavern($roomId);

        return response()->json([
            'message' => "Home tavern set to room {$roomId}.",
            'home_tavern_room_id' => $roomId,
        ]);
    }

    private function travelTo(Character $character, TeleportService $service, int $destination): JsonResponse
    {
        $navigator = Navigator::forCharacter($character);
        $from = $navigator->loadCurrentRoom()->curRoom;

        $plan = new TeleportPlanner(RoomGraph::fromDatabase())->plan(
            $from,
            $destination,
            $service->usableAnchors(),
            $character->home_tavern_room_id,
        );

        if ($plan === null) {
            return response()->json([
                'message' => "No route from room {$from} to room {$destination}, with or without a teleport.",
            ], 422);
        }

        if ($plan->anchor !== null) {
            $service->jump($plan->anchor);
        } elseif ($plan->useHomeTavern) {
            $service->toHomeTavern();
        }

        $blob = $plan->steps() > 0 ? $navigator->walk($plan->walkPath) : $navigator->loadCurrentRoom();

        return $this->arrived(
            $blob->curRoom,
            $blob->name,
            jumped: $plan->usesTeleport(),
            steps: $plan->steps(),
            anchor: $plan->anchor?->name,
        );
    }

    private function arrived(int $roomId, string $roomName, bool $jumped, int $steps, ?string $anchor = null): JsonResponse
    {
        return response()->json([
            'message' => "Arrived in {$roomName} (room {$roomId}).",
            'room_id' => $roomId,
            'room_name' => $roomName,
            'teleported' => $jumped,
            'anchor' => $anchor,
            'steps_walked' => $steps,
        ]);
    }
}
