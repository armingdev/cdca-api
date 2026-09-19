<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCrewsRequest;
use App\Http\Resources\CrewResource;
use App\Models\Crew;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CrewController extends Controller
{
    /**
     * The crews we have seen so far — a crew enters this table the first time
     * any run reads its roster or meets one of its members — searchable by
     * name or by the game's crew id, so a crew-members run can be set up from
     * a picker instead of a number copied out of crew_profile.php.
     *
     * Game-world data, not user data: every user sees the same crews.
     */
    public function index(IndexCrewsRequest $request): AnonymousResourceCollection
    {
        $crews = Crew::query()
            ->when($request->integer('server_id'), fn ($query, $server) => $query->where('server_id', $server))
            ->when($request->validated('search'), function ($query, string $search) {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn ($query) => $query
                    ->where('name', 'ilike', $term)
                    ->when(ctype_digit($search), fn ($query) => $query->orWhere('game_crew_id', (int) $search)));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 25));

        return CrewResource::collection($crews);
    }
}
