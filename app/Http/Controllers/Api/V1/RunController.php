<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Combat\DropTotals;
use App\Game\Engine\MobRunConfig;
use App\Game\Engine\PvpRunConfig;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunDispatcher;
use App\Game\Engine\RunLauncher;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Game\Exceptions\CharactersBusyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBattleEventsRequest;
use App\Http\Requests\IndexRunEventsRequest;
use App\Http\Requests\IndexRunsRequest;
use App\Http\Requests\StoreRunRequest;
use App\Http\Requests\UpdateRunRequest;
use App\Http\Resources\BattleEventResource;
use App\Http\Resources\RunEventResource;
use App\Http\Resources\RunResource;
use App\Models\AttackList;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Quest;
use App\Models\QuestList;
use App\Models\Run;
use App\Models\RunEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RunController extends Controller
{
    /**
     * The run history grows without bound, so this is the one index that
     * paginates — the fleet-shaped lists (characters, RGAs, skills) stay whole.
     */
    public function index(IndexRunsRequest $request): AnonymousResourceCollection
    {
        return RunResource::collection(
            $request->user()->runs()
                ->with('participants.character')
                ->latest()
                // created_at ties (runs launched in the same second) must not reorder between polls.
                ->latest('id')
                ->paginate($request->integer('per_page', 25))
                ->withQueryString()
        );
    }

    public function store(StoreRunRequest $request, RunLauncher $launcher): JsonResponse
    {
        $user = $request->user();
        $mode = RunMode::from($request->validated('mode'));

        $characters = Character::query()
            ->whereIn('id', $request->validated('characters'))
            ->whereHas('rga', fn ($query) => $query->where('user_id', $user->id))
            ->get();

        if ($characters->count() !== count($request->validated('characters'))) {
            throw ValidationException::withMessages(['characters' => ['One or more characters do not belong to you.']]);
        }

        $config = $this->buildConfig($mode, $request, $user->id);

        try {
            $run = $launcher->launch(
                mode: $mode,
                characters: $characters,
                config: $config,
                castOnStart: $request->boolean('cast_on_start'),
                requireCircumspect: $request->boolean('require_circumspect'),
                restartEveryMinutes: $request->filled('restart_every_minutes') ? $request->integer('restart_every_minutes') : null,
                startAt: $request->filled('start_at') ? Carbon::parse($request->validated('start_at')) : null,
                user: $user,
                skillIds: $request->has('skill_ids') ? $request->validated('skill_ids') : null,
                name: $request->validated('name'),
                reserveRageFor: array_values($request->validated('reserve_rage_for') ?? []),
                reserveRageHours: $request->integer('reserve_rage_hours', 12),
            );
        } catch (CharactersBusyException $exception) {
            throw ValidationException::withMessages(['characters' => [$exception->getMessage()]]);
        }

        return RunResource::make($run->load('participants.character'))->response()->setStatusCode(201);
    }

    public function show(Run $run): RunResource
    {
        Gate::authorize('view', $run);

        return RunResource::make($run->load('participants.character'));
    }

    /**
     * Rename a run. Allowed in any status — the label is for the person
     * reading the list, not for the workers.
     */
    public function update(UpdateRunRequest $request, Run $run): RunResource
    {
        Gate::authorize('update', $run);

        $run->update(['name' => $request->validated('name')]);

        return RunResource::make($run->load('participants.character'));
    }

    /**
     * Request a graceful stop: every worker exits at its next loop iteration;
     * parked participants are finalized immediately. Terminal — a stopped run
     * cannot be resumed.
     */
    public function stop(Run $run): RunResource
    {
        Gate::authorize('update', $run);

        $run->requestStop();

        return RunResource::make($run->fresh()->load('participants.character'));
    }

    /**
     * Request a graceful pause: workers park at their next loop iteration
     * with progress persisted; resume continues where each character left off.
     */
    public function pause(Run $run): RunResource
    {
        Gate::authorize('update', $run);

        if (! in_array($run->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true)) {
            throw ValidationException::withMessages(['run' => ['Only a pending, running, or waiting run can be paused.']]);
        }

        $run->requestPause();

        return RunResource::make($run->fresh()->load('participants.character'));
    }

    /**
     * Resume a paused run: paused participants are re-dispatched and continue
     * from their persisted progress; skill options (cast-on-start selection,
     * Circumspect gate) are re-applied at pickup, so selection changes made
     * while paused take effect.
     */
    public function resume(Run $run, RunDispatcher $dispatcher): RunResource
    {
        Gate::authorize('update', $run);

        if ($run->status !== RunStatus::Paused) {
            throw ValidationException::withMessages(['run' => ['Only a paused run can be resumed.']]);
        }

        $run->clearSignal();

        $participants = $run->participants()->where('status', RunStatus::Paused)->get();

        foreach ($participants as $participant) {
            $participant->transition(RunStatus::Pending, 'Resuming…');
            $dispatcher->dispatch($participant);
        }

        $run->update(['status' => RunStatus::Running]);

        return RunResource::make($run->fresh()->load('participants.character'));
    }

    /**
     * Delete a finished run (and its participants via cascade). Live or
     * parked runs must be stopped first.
     */
    public function destroy(Run $run): JsonResponse
    {
        Gate::authorize('delete', $run);

        if (! $run->status->isFinished()) {
            throw ValidationException::withMessages(['run' => ['Stop the run before deleting it.']]);
        }

        $run->delete();

        return response()->json(['message' => 'Run deleted.']);
    }

    /**
     * Battle events across the run's characters (newest first, paginated).
     */
    public function battles(IndexBattleEventsRequest $request, Run $run): AnonymousResourceCollection
    {
        Gate::authorize('view', $run);

        $events = $this->battlesOf($run)
            ->with('mob:id,name')
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 50));

        return BattleEventResource::collection($events);
    }

    /**
     * What this run has dropped so far, per item and source mob.
     */
    public function drops(Run $run, DropTotals $totals): JsonResponse
    {
        Gate::authorize('view', $run);

        $rows = $totals->byDropAndMob($this->battlesOf($run));

        return response()->json([
            'drops' => $rows,
            'total' => $rows->sum('count'),
        ]);
    }

    /**
     * The battles fought by this run. Runs from before battles were tagged
     * with their run have none, and fall back to what the list used to show:
     * their characters' battles since the run was created.
     *
     * @return Builder<BattleEvent>
     */
    private function battlesOf(Run $run): Builder
    {
        if (BattleEvent::where('run_id', $run->id)->exists()) {
            return BattleEvent::query()->where('battle_events.run_id', $run->id);
        }

        return BattleEvent::query()
            ->whereNull('battle_events.run_id')
            ->whereIn('battle_events.character_id', $run->participants()->select('character_id'))
            ->where('occurred_at', '>=', $run->created_at);
    }

    /**
     * The run's durable decision log (newest first, paginated). Unlike the
     * participant's last_activity one-liner this survives the next message,
     * so a finished run can still say which quests it skipped and why.
     */
    public function events(IndexRunEventsRequest $request, Run $run): AnonymousResourceCollection
    {
        Gate::authorize('view', $run);

        $events = RunEvent::query()
            ->where('run_id', $run->id)
            ->when($request->filled('participant_id'), fn ($query) => $query->where('run_participant_id', $request->integer('participant_id')))
            ->when($request->filled('character_id'), fn ($query) => $query->where('character_id', $request->integer('character_id')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->validated('type')))
            ->when($request->filled('level'), fn ($query) => $query->where('level', $request->validated('level')))
            ->when($request->filled('after_id'), fn ($query) => $query->where('id', '>', $request->integer('after_id')))
            ->with('character:id,name')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return RunEventResource::collection($events);
    }

    /**
     * Who to talk to and which quest to ask for. A catalog pick carries both;
     * the engine itself works from the giver's name and the game's quest id.
     *
     * @return array{npcName: string, questId: int}
     */
    private function questTarget(StoreRunRequest $request): array
    {
        if (! $request->filled('catalog_quest_id')) {
            return ['npcName' => $request->validated('npc'), 'questId' => $request->integer('quest_id')];
        }

        $quest = Quest::findOrFail($request->integer('catalog_quest_id'));

        if ($quest->giver === null) {
            throw ValidationException::withMessages([
                'catalog_quest_id' => ["The catalog does not know who gives {$quest->name} yet."],
            ]);
        }

        return ['npcName' => $quest->giver, 'questId' => $quest->game_quest_id];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConfig(RunMode $mode, StoreRunRequest $request, int $userId): array
    {
        $stopRage = $request->integer('stop_rage', 2500);
        $levelUp = $request->boolean('level_up');
        $smart = $request->boolean('smart');
        $respawnWaitSeconds = $request->filled('respawn_wait_seconds')
            ? $request->integer('respawn_wait_seconds')
            : QuestRunConfig::DEFAULT_RESPAWN_WAIT_SECONDS;
        $skipShardQuests = $request->boolean('skip_shard_quests', QuestRunConfig::DEFAULT_SKIP_SHARD_QUESTS);

        return match ($mode) {
            RunMode::Mob => (new MobRunConfig(
                mobNames: $request->validated('mobs'),
                stopRage: $stopRage,
                maxKills: $request->integer('max_kills'),
                levelUp: $levelUp,
                dropJunk: $request->boolean('drop_junk'),
                runCount: $request->integer('run_count'),
                attackIntervalSeconds: $request->filled('attack_interval_seconds')
                    ? $request->integer('attack_interval_seconds')
                    : null,
                smart: $smart,
            ))->toArray(),

            RunMode::Quest => (new QuestRunConfig(
                ...$this->questTarget($request),
                stopRage: $stopRage,
                levelUp: $levelUp,
                smart: $smart,
                respawnWaitSeconds: $respawnWaitSeconds,
                skipShardQuests: $skipShardQuests,
            ))->toArray(),

            RunMode::QuestList => (new QuestListRunConfig(
                questListId: $this->ownedQuestListId($request, $userId),
                stopRage: $stopRage,
                levelUp: $levelUp,
                smart: $smart,
                respawnWaitSeconds: $respawnWaitSeconds,
                skipShardQuests: $skipShardQuests,
            ))->toArray(),

            // All five PvP modes share one config; they differ only in which
            // target-source field the factory reads.
            RunMode::PvpAttackList,
            RunMode::PvpCrewHitlist,
            RunMode::PvpCrewMembers,
            RunMode::PvpBrawl,
            RunMode::PvpFactionBrawl => (new PvpRunConfig(
                targets: $request->validated('targets') ?? [],
                attackListId: $request->filled('attack_list_id')
                    ? $this->ownedAttackListId($request, $userId)
                    : null,
                crewGameIds: array_map(intval(...), $request->validated('crew_game_ids') ?? array_filter([$request->validated('crew_game_id')])),
                attacksPerTarget: $request->integer('attacks_per_target', 1),
                stopRage: $stopRage,
                message: (string) $request->input('message', ''),
                skipTooStrong: $request->boolean('skip_too_strong', true),
                autoEnterBrawl: $request->boolean('auto_enter_brawl'),
                maxAttacks: $request->filled('max_attacks') ? $request->integer('max_attacks') : null,
                cooldownMinutes: $request->integer('cooldown_minutes', 60),
            ))->toArray($mode),
        };
    }

    private function ownedAttackListId(StoreRunRequest $request, int $userId): int
    {
        $attackList = AttackList::where('id', $request->integer('attack_list_id'))
            ->where('user_id', $userId)
            ->first();

        if ($attackList === null) {
            throw ValidationException::withMessages(['attack_list_id' => ['Attack list not found.']]);
        }

        return $attackList->id;
    }

    private function ownedQuestListId(StoreRunRequest $request, int $userId): int
    {
        $questList = QuestList::where('id', $request->integer('quest_list_id'))
            ->where('user_id', $userId)
            ->first();

        if ($questList === null) {
            throw ValidationException::withMessages(['quest_list_id' => ['Quest list not found.']]);
        }

        return $questList->id;
    }
}
