<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Skills\SkillCaster;
use App\Game\Skills\SkillSyncService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CastSkillRequest;
use App\Http\Requests\UpdateCharacterSkillsRequest;
use App\Http\Resources\CharacterSkillResource;
use App\Models\Character;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CharacterSkillController extends Controller
{
    public function index(Character $character): AnonymousResourceCollection
    {
        Gate::authorize('view', $character);

        return CharacterSkillResource::collection(
            $character->skills()->with('skill')->get()
        );
    }

    /**
     * Replace the character's cast-on-start selection with the given skill ids.
     */
    public function update(UpdateCharacterSkillsRequest $request, Character $character): AnonymousResourceCollection
    {
        Gate::authorize('update', $character);

        $selected = collect($request->validated('skill_ids'))->unique();

        foreach ($selected as $skillId) {
            $character->skills()->updateOrCreate(['skill_id' => $skillId], ['cast_on_start' => true]);
        }

        $character->skills()->whereNotIn('skill_id', $selected)->update(['cast_on_start' => false]);

        return CharacterSkillResource::collection(
            $character->skills()->where('cast_on_start', true)->with('skill')->get()
        );
    }

    /**
     * Sync the character's skill state (levels, points, buffs) from the game.
     */
    public function sync(Character $character): JsonResponse
    {
        Gate::authorize('update', $character);

        $result = SkillSyncService::forCharacter($character)->sync();

        return response()->json([
            'message' => "Synced {$result->rowsSynced} skill(s).",
            'rows_synced' => $result->rowsSynced,
            'skills_discovered' => $result->skillsDiscovered,
            'skill_points' => $result->skillPoints,
            'school' => $result->school,
            'active_buffs' => $result->activeBuffs,
            'skills' => CharacterSkillResource::collection(
                $character->skills()->with('skill')->get()
            ),
        ]);
    }

    /**
     * Train one skill (spends a skill point; guarded by school lock, unlock
     * level, single-level, and point balance).
     */
    public function train(Character $character, Skill $skill): JsonResponse
    {
        Gate::authorize('update', $character);

        $result = SkillSyncService::forCharacter($character)->train($skill);

        if (! $result->success) {
            return response()->json(['message' => $result->message], 422);
        }

        return response()->json([
            'message' => $result->message,
            'new_level' => $result->newLevel,
            'skill_points' => $result->skillPointsRemaining,
            'skill' => new CharacterSkillResource(
                $character->skills()->with('skill')->where('skill_id', $skill->id)->firstOrFail()
            ),
        ]);
    }

    /**
     * Cast one skill now, or the whole cast-on-start set.
     */
    public function cast(CastSkillRequest $request, Character $character): JsonResponse
    {
        Gate::authorize('update', $character);

        $caster = SkillCaster::forCharacter($character);

        if ($request->boolean('on_start')) {
            $count = $caster->castOnStart();

            return response()->json(['message' => "Cast {$count} skill(s).", 'cast' => $count]);
        }

        $skill = Skill::findOrFail($request->validated('skill_id'));

        if (! $caster->cast($skill)) {
            return response()->json(['message' => "Failed to cast {$skill->name} (rage, cooldown, or not learned)."], 422);
        }

        return response()->json(['message' => "Cast {$skill->name}."]);
    }
}
