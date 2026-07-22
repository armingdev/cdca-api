<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MobResource;
use App\Http\Resources\RoomResource;
use App\Models\Mob;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorldController extends Controller
{
    public function showRoom(Room $room): RoomResource
    {
        return RoomResource::make($room->load('mobs'));
    }

    /**
     * Search mobs by name (for picking farm / attack targets).
     */
    public function mobs(Request $request): AnonymousResourceCollection
    {
        $mobs = Mob::query()
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where('name', 'ilike', "%{$q}%"))
            ->with('rooms:id')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return MobResource::collection($mobs);
    }
}
