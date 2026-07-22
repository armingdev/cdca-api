<?php

namespace App\Http\Controllers\Api\V1;

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\PvpRunConfig;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunLauncher;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRunRequest;
use App\Http\Resources\BattleEventResource;
use App\Http\Resources\RunResource;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\QuestList;
use App\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RunController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RunResource::collection(
            $request->user()->runs()->with('participants.character')->latest()->get()
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

        return RunResource::make($run->load('participants.character'))->response()->setStatusCode(201);
    }

    public function show(Run $run): RunResource
    {
        Gate::authorize('view', $run);

        return RunResource::make($run->load('participants.character'));
    }

    /**
     * Request a graceful stop: every worker exits at its next loop iteration.
     */
    public function stop(Run $run): RunResource
    {
        Gate::authorize('update', $run);

        $run->update(['status' => RunStatus::Stopping, 'restart_every_minutes' => null]);
        $run->participants()
            ->whereIn('status', [RunStatus::Pending, RunStatus::Running])
            ->update(['status' => RunStatus::Stopping]);
        $run->refreshStatus();

        return RunResource::make($run->fresh()->load('participants.character'));
    }

    /**
     * Battle events across the run's characters (newest first, paginated).
     */
    public function battles(Request $request, Run $run): AnonymousResourceCollection
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

        return match ($mode) {
            RunMode::Mob => (new MobRunConfig(
                mobNames: $request->validated('mobs'),
                stopRage: $stopRage,
                maxKills: $request->integer('max_kills'),
                levelUp: $levelUp,
            ))->toArray(),

            RunMode::Quest => (new QuestRunConfig(
                npcName: $request->validated('npc'),
                questId: $request->integer('quest_id'),
                stopRage: $stopRage,
                levelUp: $levelUp,
            ))->toArray(),

            RunMode::QuestList => (new QuestListRunConfig(
                questListId: $this->ownedQuestListId($request, $userId),
                stopRage: $stopRage,
                levelUp: $levelUp,
            ))->toArray(),

            RunMode::Pvp => (new PvpRunConfig(
                targets: $request->validated('targets'),
                attackRage: $request->integer('attack_rage', 50),
                attacksPerTarget: $request->integer('attacks_per_target', 1),
                stopRage: $stopRage,
                message: (string) $request->input('message', ''),
            ))->toArray(),
        };
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
