<?php

namespace App\Game\Quest;

use App\Game\Combat\StatsService;
use App\Game\Data\MobSighting;
use App\Game\Data\QuestObjective;
use App\Game\Data\QuestStepPage;
use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Engine\MobRunSummary;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\QuestRunSummary;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\QuestObjectiveType;
use App\Game\Enums\RunSignal;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\QuestNotAvailableException;
use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
use App\Game\World\TeleportPlanner;
use App\Game\World\TeleportService;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\QuestItem;
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
    private const int MAX_COMPASS_STEPS = 150;

    private int $stepsCompleted = 0;

    private int $expGained = 0;

    private int $kills = 0;

    public function __construct(
        private readonly Character $character,
        private readonly QuestRunConfig $config,
        private readonly QuestService $questService,
        private readonly Navigator $navigator,
        private readonly RoomGraph $graph,
        private readonly StatsService $stats,
        private readonly ?TeleportService $teleports = null,
    ) {}

    public static function forCharacter(Character $character, QuestRunConfig $config): self
    {
        return new self(
            $character,
            $config,
            QuestService::forCharacter($character),
            Navigator::forCharacter($character),
            RoomGraph::fromDatabase(),
            StatsService::forCharacter($character),
            TeleportService::forCharacter($character),
        );
    }

    /**
     * @param  Closure(string): void|null  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle  forwarded to objective farming
     */
    public function run(?Closure $log = null, ?Closure $signal = null, ?Closure $onBattle = null): QuestRunSummary
    {
        $log ??= fn (string $message) => null;

        if ($this->config->smart) {
            $this->levelUpToQuestRequirement($log);
        }

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
            $control = $signal !== null ? $signal() : RunSignal::None;

            if ($control === RunSignal::Stop) {
                return $this->summary(completed: false, reason: 'Stop requested.', endReason: RunEndReason::ExternalStop);
            }

            if ($control === RunSignal::Pause) {
                return $this->summary(completed: false, reason: 'Pause requested.', endReason: RunEndReason::ExternalPause);
            }

            if ($control === RunSignal::CircumspectExpired) {
                return $this->summary(completed: false, reason: 'Circumspect expired.', endReason: RunEndReason::CircumspectExpired);
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
                    return $this->summary(completed: true, reason: 'Quest complete.', endReason: RunEndReason::Completed);
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

                $farm = $this->fulfill($objective, $log, $signal, $onBattle);
                $wins = $farm?->wins ?? 0;
                $this->kills += $wins;

                // Surface an external signal from the nested farm without
                // walking back to the giver first — the resume re-navigates.
                if ($farm !== null && $farm->endReason === RunEndReason::ExternalStop) {
                    return $this->summary(completed: false, reason: 'Stop requested.', endReason: RunEndReason::ExternalStop);
                }

                if ($farm !== null && $farm->endReason === RunEndReason::ExternalPause) {
                    return $this->summary(completed: false, reason: 'Pause requested.', endReason: RunEndReason::ExternalPause);
                }

                if ($farm !== null && $farm->endReason === RunEndReason::CircumspectExpired) {
                    return $this->summary(completed: false, reason: $farm->stopReason, endReason: RunEndReason::CircumspectExpired);
                }

                if ($farm !== null && $farm->endReason === RunEndReason::RageExhausted) {
                    return $this->summary(completed: false, reason: $farm->stopReason, endReason: RunEndReason::RageExhausted);
                }

                // Walking back to the giver would only re-enter the same
                // losing fight, so surface the verdict as it stands.
                if ($farm !== null && $farm->endReason === RunEndReason::Outmatched) {
                    return $this->summary(completed: false, reason: $farm->stopReason, endReason: RunEndReason::Outmatched);
                }

                // The objective's targets are all corpses right now. They
                // respawn on the game's timer, so parking beats giving up —
                // and walking back to the giver first would waste the trip.
                // Kill and collect objectives share this path: both farm the
                // same mobs, only the game-side progress metric differs.
                if ($farm !== null
                    && $farm->endReason === RunEndReason::Completed
                    && $wins === 0
                    && $farm->sawDeadTargets
                ) {
                    return $this->summary(
                        completed: false,
                        reason: "All '{$objective->target}' targets are dead — waiting for respawn.",
                        endReason: RunEndReason::TargetsDepleted,
                    );
                }

                $this->navigateToNpc();

                if ($wins === 0) {
                    return $this->summary(
                        completed: false,
                        reason: "Could not make progress on objective '{$objective->target}'.",
                        endReason: RunEndReason::Stuck,
                    );
                }

                continue;
            }

            $nextStep = $this->stepIdFromLink($page->continueLink);

            if ($nextStep === null) {
                return $this->summary(
                    completed: false,
                    reason: "Step {$stepId} has no actionable link.",
                    endReason: RunEndReason::Stuck,
                );
            }

            $stepId = $nextStep;
        }
    }

    /**
     * Farm the objective's target. Returns the nested farm summary (its end
     * reason distinguishes rage-out from "no way to progress"), or null when
     * no farmable mob is known or the farm could not start.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     */
    private function fulfill(QuestObjective $objective, Closure $log, ?Closure $signal, ?Closure $onBattle): ?MobRunSummary
    {
        $mobNames = match ($objective->type) {
            QuestObjectiveType::Kill => [$objective->target],
            QuestObjectiveType::Collect => $this->sourceMobsFor($objective->target),
            QuestObjectiveType::Talk => [],
        };

        if ($mobNames === [] && $objective->type === QuestObjectiveType::Collect) {
            $mobNames = $this->learnSourcesViaHelper($objective, $log);
        }

        if ($mobNames === []) {
            $log("No known way to fulfill '{$objective->target}' ({$objective->type->value}).");

            return null;
        }

        $config = new MobRunConfig(
            mobNames: $mobNames,
            stopRage: $this->config->stopRage,
            maxKills: max($objective->remaining(), 1),
            levelUp: $this->config->levelUp,
            smart: $this->config->smart,
        );

        try {
            return MobRunner::forCharacter($this->character, $config)
                ->run(log: $log, signal: $signal, onBattle: $onBattle);
        } catch (GameException $exception) {
            $log($exception->getMessage());

            return null;
        }
    }

    /**
     * Smart mode: a quest the character is too low for is never offered by the
     * giver, so spend banked exp before the walk. Seeded required levels can be
     * stale, so this never blocks the run — it levels what it can and tries.
     *
     * @param  Closure(string): void  $log
     */
    private function levelUpToQuestRequirement(Closure $log): void
    {
        $required = Quest::where('game_quest_id', $this->config->questId)->value('required_level');

        if ($required === null || $this->stats->refresh()->level >= $required) {
            return;
        }

        // tryLevelUp() refreshes the character record it shares with us, so
        // each new level is visible on the next pass of the loop.
        while ($this->character->level < $required && $this->stats->tryLevelUp()) {
            //
        }

        $log($this->character->level >= $required
            ? "Leveled to {$this->character->level} for quest {$this->config->questId}."
            : "Quest {$this->config->questId} wants level {$required}, character is {$this->character->level} — trying anyway.");
    }

    /**
     * Last-resort source discovery for a collect item nothing in the DB knows
     * about: turn on the game's own "find my target" compass and follow it
     * room by room (the room blob carries a direction until we stand in the
     * designated target room). The destination is stored on the quest item,
     * and the mobs sighted there become the farm targets — their drops then
     * pin the true sources in battle_events for every future run.
     *
     * @param  Closure(string): void  $log
     * @return list<string>
     */
    private function learnSourcesViaHelper(QuestObjective $objective, Closure $log): array
    {
        $toggle = collect($this->questService->helperToggles())
            ->first(fn ($candidate) => $candidate->isCollect() && $candidate->itemName === $objective->target);

        if ($toggle === null) {
            $log("No quest-helper toggle found for '{$objective->target}'.");

            return [];
        }

        $log("Following the quest-helper compass for '{$objective->target}'…");
        $this->questService->setQuestHelp($toggle, true);

        try {
            $blob = $this->navigator->loadCurrentRoom();

            for ($steps = 0; $blob->questHelpDirection !== null; $steps++) {
                $next = $blob->exits[$blob->questHelpDirection] ?? null;

                if ($next === null || $steps >= self::MAX_COMPASS_STEPS) {
                    $log("Compass walk aborted (step {$steps}, direction '{$blob->questHelpDirection}').");

                    return [];
                }

                $blob = $this->navigator->stepTo($next, $blob->curRoom);
                $this->graph->addRoom($blob->curRoom, $blob->exits);
            }

            $item = QuestItem::firstOrNew(['name' => $objective->target]);
            $item->source_mobs ??= [];
            $item->target_room_id = $blob->curRoom;
            $item->helper_verified_at = now();
            $item->save();

            $mobNames = collect($blob->mobs)->pluck('name')->unique()->values()->all();
            $log(sprintf(
                'Compass arrived in room %d after %d step(s); farming %s.',
                $blob->curRoom,
                $steps,
                $mobNames === [] ? 'nothing — room is empty' : implode(', ', $mobNames),
            ));

            return $mobNames;
        } finally {
            try {
                $this->questService->setQuestHelp($toggle, false);
            } catch (GameException) {
                // Leaving the compass on is harmless; never mask the real outcome.
            }
        }
    }

    /**
     * Mobs known to drop the item: the seeded catalog (xowh QuestItems)
     * plus mobs empirically observed dropping it in recorded battles.
     * Seeded names are filtered to mobs we have mapped, so MobRunner only
     * ever hunts findable targets.
     *
     * @return list<string>
     */
    private function sourceMobsFor(string $itemName): array
    {
        $observed = BattleEvent::query()
            ->where('drop_name', $itemName)
            ->whereNotNull('mob_id')
            ->distinct()
            ->pluck('mob_id')
            ->pipe(fn ($ids) => Mob::whereIn('id', $ids)->pluck('name'));

        $seeded = QuestItem::where('name', $itemName)->first()?->source_mobs ?? [];

        return $observed
            ->merge(Mob::whereIn('name', $seeded)->pluck('name'))
            ->unique()
            ->values()
            ->all();
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
            // Free item anchors only — a quest run must not spend rage or the
            // Teleport skill's cooldown just to reach the giver.
            $plan = new TeleportPlanner($this->graph)->planToNearest(
                $blob->curRoom,
                fn (int $roomId): bool => $rooms->contains($roomId),
                $this->teleports?->freeAnchors() ?? [],
            );

            if ($plan === null) {
                throw new GameException("No path to quest-giver '{$this->config->npcName}'.");
            }

            $blob = $this->teleports !== null
                ? $this->teleports->travel($plan)
                : $this->navigator->walk($plan->walkPath);
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

    private function summary(bool $completed, string $reason, RunEndReason $endReason): QuestRunSummary
    {
        return new QuestRunSummary(
            completed: $completed,
            stepsCompleted: $this->stepsCompleted,
            expGained: $this->expGained,
            kills: $this->kills,
            stopReason: $reason,
            endReason: $endReason,
        );
    }
}
