<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SkillResource;
use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SkillController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $skills = Skill::query()
            ->when($request->string('school')->toString(), fn ($query, $school) => $query->where('school', $school))
            ->orderBy('school')
            ->orderBy('name')
            ->get();

        return SkillResource::collection($skills);
    }
}
