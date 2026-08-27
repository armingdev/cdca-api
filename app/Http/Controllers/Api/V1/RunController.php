<?php

namespace App\Http\Controllers\Api\V1;

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
use App\Http\Requests\IndexRunsRequest;
use App\Http\Requests\StoreRunRequest;
use App\Http\Resources\BattleEventResource;
use App\Http\Resources\RunResource;
use App\Models\AttackList;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\QuestList;
use App\Models\Run;
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

        $characterIds = $run->participants()->pluck('character_id');

        $events = BattleEvent::query()
            ->whereIn('character_id', $characterIds)
            ->with('mob:id,name')
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 50));

        return BattleEventResource::collection($events);
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
                npcName: $request->validated('npc'),
                questId: $request->integer('quest_id'),
                stopRage: $stopRage,
                levelUp: $levelUp,
                smart: $smart,
                respawnWaitSeconds: $respawnWaitSeconds,
            ))->toArray(),

            RunMode::QuestList => (new QuestListRunConfig(
                questListId: $this->ownedQuestListId($request, $userId),
                stopRage: $stopRage,
                levelUp: $levelUp,
                smart: $smart,
                respawnWaitSeconds: $respawnWaitSeconds,
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
                crewGameId: $request->filled('crew_game_id') ? $request->integer('crew_game_id') : null,
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
