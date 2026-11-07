<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPotionsRequest;
use App\Http\Resources\PotionResource;
use App\Models\Potion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The potion catalog (read-only reference data, ~140 rows — not paginated).
 */
class PotionController extends Controller
{
    public function index(IndexPotionsRequest $request): AnonymousResourceCollection
    {
        $potions = Potion::query()
            ->when($request->validated('kind'), fn ($query, string $kind) => $query->where('kind', $kind))
            ->when($request->validated('search'), fn ($query, string $search) => $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return PotionResource::collection($potions);
    }
}
