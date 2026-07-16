<?php

namespace App\Game\Quest;

use App\Game\Data\MobSighting;
use App\Game\Data\QuestObjective;
use App\Game\Data\QuestStepPage;
use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\QuestRunSummary;
use App\Game\Enums\QuestObjectiveType;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\QuestNotAvailableException;
use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Mob;
use Closure;

/**
 * Single-quest state machine. Walk to the giver, accept the quest, then for
 * each step: if the game shows the finish link (accept step, or objective
 * met) follow it and advance to the continue link; otherwise fulfill the
 * unmet objective by driving MobRunner against the named mob (or the item's
 * source mobs) and re-view the step. Ends when a finished step offers no
 * continue link (quest complete).
 */
class QuestRunner
{
    private int $stepsCompleted = 0;

    private int $expGained = 0;

    private int $kills = 0;

    public function __construct(
        private readonly Character $character,
        private readonly QuestRunConfig $config,
        private readonly QuestService $questService,
        private readonly Navigator $navigator,
        private readonly RoomGraph $graph,
    ) {}

    public static function forCharacter(Character $character, QuestRunConfig $config): self
    {
        return new self(
            $character,
            $config,
            QuestService::forCharacter($character),
            Navigator::forCharacter($character),
            RoomGraph::fromDatabase(),
        );
    }

    /**
     * @param  Closure(string): void|null  $log
     * @param  Closure(): bool|null  $shouldStop
     * @param  Closure(BattleEvent): void|null  $onBattle  forwarded to objective farming
     */
    public function run(?Closure $log = null, ?Closure $shouldStop = null, ?Closure $onBattle = null): QuestRunSummary
    {
        $log ??= fn (string $message) => null;

        $npc = $this->navigateToNpc();
        $quest = collect($this->questService->availableQuests($npc->spawnId, $npc->hash))
            ->firstWhere('questId', $this->config->questId);

        if ($quest === null) {
            throw new QuestNotAvailableException($this->config->questId, $this->config->npcName);
        }

        $log("Accepting quest {$this->config->questId} from {$this->config->npcName}.");

        $npcId = $quest->npcId;
        $stepId = $quest->firstStepId;
        $sendQuestId = true;

        while (true) {
            if ($shouldStop !== null && $shouldStop()) {
                return $this->summary(completed: false, reason: 'Stop requested.', externallyStopped: true);
            }

            $page = $this->questService->viewStep($npcId, $stepId, $sendQuestId ? $this->config->questId : null);
            $sendQuestId = false;

            if ($page->canAdvance()) {
                $finished = $this->questService->finishStep($page->finishLink);
                $this->stepsCompleted++;
                $this->expGained += $finished->expReward ?? 0;
                $log($this->completionLine($stepId, $finished));

                $nextStep = $this->stepIdFromLink($finished->continueLink);

                if ($nextStep === null) {
                    return $this->summary(completed: true, reason: 'Quest complete.');
                }

                $stepId = $nextStep;

                continue;
            }

            if ($page->hasObjectives()) {
                $objective = $page->unmetObjectives()[0];
                $log(sprintf(
                    'Objective: %s %d/%d %s',
                    $objective->target,
                    $objective->current,
                    $objective->required,
                    $objective->type->value,
                ));

                $wins = $this->fulfill($objective, $log, $shouldStop, $onBattle);
                $this->kills += $wins;
                $this->navigateToNpc();

                if ($wins === 0) {
                    return $this->summary(
                        completed: false,
                        reason: "Could not make progress on objective '{$objective->target}'.",
                    );
                }

                continue;
            }

            $nextStep = $this->stepIdFromLink($page->continueLink);

            if ($nextStep === null) {
                return $this->summary(completed: false, reason: "Step {$stepId} has no actionable link.");
            }

            $stepId = $nextStep;
        }
    }

    /**
     * Farm the objective's target. Returns the number of wins so a zero result
     * (no targets / rage floor) can break a stuck loop.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): bool|null  $shouldStop
     * @param  Closure(BattleEvent): void|null  $onBattle
     */
    private function fulfill(QuestObjective $objective, Closure $log, ?Closure $shouldStop, ?Closure $onBattle): int
    {
        $mobNames = match ($objective->type) {
            QuestObjectiveType::Kill => [$objective->target],
            QuestObjectiveType::Collect => $this->sourceMobsFor($objective->target),
            QuestObjectiveType::Talk => [],
        };

        if ($mobNames === []) {
            $log("No known way to fulfill '{$objective->target}' ({$objective->type->value}).");

            return 0;
        }

        $config = new MobRunConfig(
            mobNames: $mobNames,
            stopRage: $this->config->stopRage,
            maxKills: max($objective->remaining(), 1),
            levelUp: $this->config->levelUp,
        );

        try {
            return MobRunner::forCharacter($this->character, $config)
                ->run(log: $log, shouldStop: $shouldStop, onBattle: $onBattle)
                ->wins;
        } catch (GameException $exception) {
            $log($exception->getMessage());

            return 0;
        }
    }

    /**
     * Mobs empirically known to drop the item, from recorded battle drops.
     *
     * @return list<string>
     */
    private function sourceMobsFor(string $itemName): array
    {
        return BattleEvent::query()
            ->where('drop_name', $itemName)
            ->whereNotNull('mob_id')
            ->distinct()
            ->pluck('mob_id')
            ->pipe(fn ($ids) => Mob::whereIn('id', $ids)->pluck('name')->all());
    }

    /**
     * Walk to the quest-giver's room and return its sighting (for spawn id +
     * hash). Cheap when already there.
     */
    private function navigateToNpc(): MobSighting
    {
        $rooms = Mob::where('name', $this->config->npcName)
            ->with('rooms:id')
            ->get()
            ->flatMap(fn (Mob $mob) => $mob->rooms->pluck('id'))
            ->unique()
            ->values();

        if ($rooms->isEmpty()) {
            throw new GameException("Quest-giver '{$this->config->npcName}' is not in the mapped world.");
        }

        $blob = $this->navigator->loadCurrentRoom();
        $this->graph->addRoom($blob->curRoom, $blob->exits);

        if (! $rooms->contains($blob->curRoom)) {
            $path = $this->graph->pathToNearest($blob->curRoom, fn (int $roomId): bool => $rooms->contains($roomId));

            if ($path === null) {
                throw new GameException("No path to quest-giver '{$this->config->npcName}'.");
            }

            $blob = $this->navigator->walk($path);
        }

        foreach ($blob->mobs as $sighting) {
            if ($sighting->name === $this->config->npcName) {
                return $sighting;
            }
        }

        throw new GameException("Quest-giver '{$this->config->npcName}' is not present in its room right now.");
    }

    private function completionLine(int $stepId, QuestStepPage $finished): string
    {
        $reward = $finished->expReward !== null ? " (+{$finished->expReward} exp)" : '';

        return "Completed step {$stepId}{$reward}.";
    }

    private function stepIdFromLink(?string $href): ?int
    {
        if ($href === null) {
            return null;
        }

        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        return isset($query['stepid']) ? (int) $query['stepid'] : null;
    }

    private function summary(bool $completed, string $reason, bool $externallyStopped = false): QuestRunSummary
    {
        return new QuestRunSummary(
            completed: $completed,
            stepsCompleted: $this->stepsCompleted,
            expGained: $this->expGained,
            kills: $this->kills,
            stopReason: $reason,
            externallyStopped: $externallyStopped,
        );
    }
}
