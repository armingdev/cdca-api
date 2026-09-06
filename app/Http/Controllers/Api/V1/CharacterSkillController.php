<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Skills\BuffEnsurer;
use App\Game\Skills\SkillCaster;
use App\Game\Skills\SkillSelection;
use App\Game\Skills\SkillSyncService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CastSkillRequest;
use App\Http\Requests\SyncCharacterSkillsRequest;
use App\Http\Requests\UpdateCharacterSkillsRequest;
use App\Http\Resources\CharacterSkillResource;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use Carbon\CarbonInterface;
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
    public function update(
        UpdateCharacterSkillsRequest $request,
        Character $character,
        SkillSelection $selection,
    ): AnonymousResourceCollection {
        Gate::authorize('update', $character);

        /** @var list<int> $skillIds */
        $skillIds = $request->validated('skill_ids');

        $selection->replaceFor($character, $skillIds);

        return CharacterSkillResource::collection(
            $character->skills()->where('cast_on_start', true)->with('skill')->get()
        );
    }

    /**
     * Sync the character's skill state (levels, points, buffs) from the game.
     *
     * Five throttled game reads, so the client that syncs on every character
     * selection passes `max_age_seconds`: state read more recently than that
     * is returned straight from the database with `synced: false` and costs
     * the game nothing. `with_recharge` additionally reads each trained
     * skill's authoritative cooldown, one request apiece.
     */
    public function sync(SyncCharacterSkillsRequest $request, Character $character): JsonResponse
    {
        Gate::authorize('update', $character);

        $service = SkillSyncService::forCharacter($character);
        $lastSyncedAt = $service->lastSyncedAt();

        if ($request->has('max_age_seconds')
            && $lastSyncedAt !== null
            && $lastSyncedAt->greaterThan(now()->subSeconds($request->integer('max_age_seconds')))) {
            return $this->syncResponse(
                $character,
                message: 'Skills are up to date.',
                synced: false,
                syncedAt: $lastSyncedAt,
                rowsSynced: 0,
                skillsDiscovered: 0,
            );
        }

        $result = $service->sync($request->boolean('with_recharge'));

        return $this->syncResponse(
            $character->refresh(),
            message: "Synced {$result->rowsSynced} skill(s).",
            synced: true,
            syncedAt: $service->lastSyncedAt(),
            rowsSynced: $result->rowsSynced,
            skillsDiscovered: $result->skillsDiscovered,
        );
    }

    /**
     * One response shape for both sync paths, so a client never has to branch
     * on `synced` to read the state: the counts describe what this call did,
     * every other field describes the character as it now stands.
     *
     * `skills` is a plain array, not a `data` envelope — a resource collection
     * nested inside a JSON array serializes unwrapped, and the sibling
     * teleport sync reports its list the same way.
     */
    private function syncResponse(
        Character $character,
        string $message,
        bool $synced,
        ?CarbonInterface $syncedAt,
        int $rowsSynced,
        int $skillsDiscovered,
    ): JsonResponse {
        $states = $character->skills()->with('skill')->get();

        return response()->json([
            'message' => $message,
            'synced' => $synced,
            'synced_at' => $syncedAt,
            'rows_synced' => $rowsSynced,
            'skills_discovered' => $skillsDiscovered,
            'skill_points' => $character->skill_points,
            'school' => $character->school,
            'active_buffs' => $states->filter(fn (CharacterSkill $state): bool => $state->isBuffActive())->count(),
            'skills' => CharacterSkillResource::collection($states),
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
     * Cast one skill now, or bring the whole selected set up.
     *
     * The set path reads the character's live skill state first and reports
     * per skill: a bare count hid the fact that most of the set had been
     * silently skipped, and the reasons are what the player needs.
     */
    public function cast(CastSkillRequest $request, Character $character): JsonResponse
    {
        Gate::authorize('update', $character);

        $caster = SkillCaster::forCharacter($character);

        if ($request->boolean('on_start')) {
            $result = BuffEnsurer::forCharacter($character)->ensure();

            return response()->json([
                'message' => "Cast {$result->castCount()} skill(s).",
                'cast' => $result->cast,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
            ]);
        }

        $skill = Skill::findOrFail($request->validated('skill_id'));

        if (! $caster->cast($skill)) {
            return response()->json(['message' => "Failed to cast {$skill->name} (rage, cooldown, or not learned)."], 422);
        }

        return response()->json(['message' => "Cast {$skill->name}."]);
    }
}
