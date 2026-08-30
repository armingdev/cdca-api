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
use App\Game\Engine\RunEventRecorder;
use App\Game\Enums\QuestObjectiveType;
use App\Game\Enums\RunEventType;
use App\Game\Enums\RunSignal;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\QuestNotAvailableException;
use App\Game\Exceptions\TransientGameException;
use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
use App\Game\World\TeleportPlanner;
use App\Game\World\TeleportService;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\QuestItem;
use App\Models\RunEvent;
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

    /** Steps already turned in this pass, so a re-entry cannot loop on one. */
    /** @var list<int> */
    private array $completedStepIds = [];

    private ?int $expectedSteps = null;

    public function __construct(
        private readonly Character $character,
        private readonly QuestRunConfig $config,
        private readonly QuestService $questService,
        private readonly Navigator $navigator,
        private readonly RoomGraph $graph,
        private readonly StatsService $stats,
        private readonly PurchasedQuestItems $purchasedItems,
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
            app(PurchasedQuestItems::class),
            TeleportService::forCharacter($character),
        );
    }

    /**
     * @param  Closure(string): void|null  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle  forwarded to objective farming
     * @param  Closure(): void|null  $ensureBuffs  just-in-time buff top-up, forwarded to objective farming
     * @param  RunEventRecorder|null  $events  durable log for skip/complete decisions
     */
    public function run(
        ?Closure $log = null,
        ?Closure $signal = null,
        ?Closure $onBattle = null,
        ?Closure $ensureBuffs = null,
        ?RunEventRecorder $events = null,
    ): QuestRunSummary {
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
        $reviewedStalledStep = false;

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
                $this->completedStepIds[] = $stepId;
                $this->expGained += $finished->expReward ?? 0;
                $log($this->completionLine($stepId, $finished));

                $nextStep = $this->stepIdFromLink($this->chooseContinueLink($finished, $log));

                if ($nextStep === null) {
                    // A turn-in page with no onward link is the *usual* way a
                    // quest ends, but it is also how an intermediate step
                    // renders — and taking it at face value abandoned every
                    // remaining step. Ask the giver before calling it done.
                    $nextStep = $this->reEnterUnfinishedQuest($log, $events);

                    if ($nextStep === null) {
                        $events?->record(RunEventType::QuestCompleted, "Quest {$this->config->questId} complete.", [
                            'quest_id' => $this->config->questId,
                            'steps_completed' => $this->stepsCompleted,
                            'steps_expected' => $this->expectedSteps(),
                        ]);

                        return $this->summary(completed: true, reason: 'Quest complete.', endReason: RunEndReason::Completed);
                    }

                    $sendQuestId = true;
                }

                $stepId = $nextStep;
                $reviewedStalledStep = false;

                continue;
            }

            if ($page->hasObjectives()) {
                $outcome = $this->workObjectives($page, $stepId, $reviewedStalledStep, $log, $signal, $onBattle, $ensureBuffs, $events);

                if ($outcome instanceof QuestRunSummary) {
                    return $outcome;
                }

                $reviewedStalledStep = $outcome;

                continue;
            }

            $nextStep = $this->stepIdFromLink($this->chooseContinueLink($page, $log));

            if ($nextStep === null) {
                return $this->summary(
                    completed: false,
                    reason: "Step {$stepId} has no actionable link.",
                    endReason: RunEndReason::Stuck,
                );
            }

            if ($nextStep === $stepId) {
                return $this->summary(
                    completed: false,
                    reason: "Step {$stepId} only links back to itself.",
                    endReason: RunEndReason::Stuck,
                );
            }

            $stepId = $nextStep;
        }
    }

    /**
     * Work every unmet objective on the step, then report back.
     *
     * Returns a summary when the quest must end, or the new "already re-viewed
     * a stalled step" flag when the loop should re-view the step and carry on.
     *
     * Looping matters: a step that wants ten of mob A *and* ten of mob B used
     * to inspect only the first entry, so B's mob being unfarmable condemned
     * the whole quest after A had been farmed at full rage cost.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     */
    private function workObjectives(
        QuestStepPage $page,
        int $stepId,
        bool $reviewedStalledStep,
        Closure $log,
        ?Closure $signal,
        ?Closure $onBattle,
        ?Closure $ensureBuffs,
        ?RunEventRecorder $events,
    ): QuestRunSummary|bool {
        $unmet = $page->unmetObjectives();

        // Every objective reads complete, yet the game still withholds the
        // finish link. One re-view covers a page that lagged behind a kill;
        // a second identical answer is a genuine dead end, not an excuse to
        // index into an empty list.
        if ($unmet === []) {
            if (! $reviewedStalledStep) {
                $log("Step {$stepId} shows every objective met but offers no finish link — re-checking.");

                return true;
            }

            return $this->summary(
                completed: false,
                reason: "Step {$stepId} shows every objective met but the game will not let it be finished.",
                endReason: RunEndReason::Stuck,
            );
        }

        $totalWins = 0;
        $anySourceKnown = false;
        $respawnPending = false;
        $lastTarget = null;

        foreach ($unmet as $objective) {
            $lastTarget = $objective->target;

            // Checked per objective, not just the first: a shard sitting on
            // objective three used to go unnoticed until the run had farmed
            // its way there.
            if ($this->config->skipShardQuests && $this->purchasedItems->matches($objective->target)) {
                return $this->summary(
                    completed: false,
                    reason: "Quest {$this->config->questId} needs '{$objective->target}', which the game only sells.",
                    endReason: RunEndReason::RequiresPurchasedItem,
                );
            }

            $log(sprintf(
                'Objective: %s %d/%d %s',
                $objective->target,
                $objective->current,
                $objective->required,
                $objective->type->value,
            ));

            $farm = $this->fulfill($objective, $log, $signal, $onBattle, $ensureBuffs);

            if ($farm === null) {
                // Nothing known to farm for this one — but a sibling objective
                // may still be workable, so move on instead of condemning the
                // quest here.
                $events?->record(
                    RunEventType::ObjectiveProgress,
                    "No known way to fulfill '{$objective->target}' ({$objective->type->value}).",
                    ['quest_id' => $this->config->questId, 'objective' => $objective->target],
                    RunEvent::LEVEL_WARNING,
                );

                continue;
            }

            $anySourceKnown = true;
            $wins = $farm->wins;
            $totalWins += $wins;
            $this->kills += $wins;

            // External and rage verdicts belong to the whole pass, so surface
            // them straight away rather than walking back to the giver first —
            // the resume re-navigates anyway.
            $passthrough = match ($farm->endReason) {
                RunEndReason::ExternalStop => $this->summary(false, 'Stop requested.', RunEndReason::ExternalStop),
                RunEndReason::ExternalPause => $this->summary(false, 'Pause requested.', RunEndReason::ExternalPause),
                RunEndReason::CircumspectExpired,
                RunEndReason::RageExhausted,
                RunEndReason::RageInsufficient,
                RunEndReason::Outmatched => $this->summary(false, $farm->stopReason, $farm->endReason),
                default => null,
            };

            if ($passthrough !== null) {
                return $passthrough;
            }

            // The farm stood in the target's spawn rooms and found nothing
            // alive. They come back on the game's timer, so note it and try
            // the next objective; if none of them can move, the pass parks.
            if ($farm->endReason === RunEndReason::Completed && $wins === 0 && $farm->targetsRespawnPending) {
                $respawnPending = true;
            }
        }

        if ($totalWins === 0) {
            if ($respawnPending) {
                return $this->summary(
                    completed: false,
                    reason: "All '{$lastTarget}' targets are dead — waiting for respawn.",
                    endReason: RunEndReason::TargetsDepleted,
                );
            }

            $reason = $anySourceKnown
                ? "Could not make progress on any objective of step {$stepId}."
                : "No known way to fulfill any objective of step {$stepId}.";

            $events?->record(RunEventType::ObjectiveProgress, $reason, [
                'quest_id' => $this->config->questId,
                'step_id' => $stepId,
            ], RunEvent::LEVEL_WARNING);

            return $this->summary(completed: false, reason: $reason, endReason: RunEndReason::Stuck);
        }

        $this->navigateToNpc();

        return false;
    }

    /**
     * The onward link that belongs to *this* quest.
     *
     * An NPC offering several quests renders a mob_talk link each, and simply
     * taking the first one walks the runner into someone else's step.
     *
     * @param  Closure(string): void  $log
     */
    private function chooseContinueLink(QuestStepPage $page, Closure $log): ?string
    {
        $owned = $page->continueLinkFor($this->config->questId);

        if ($owned !== null) {
            return $owned;
        }

        $first = $page->continueLink();

        if ($first !== null && count($page->continueLinks) > 1) {
            $log('Step page offers several onward links and none names this quest — taking the first.');
        }

        return $first;
    }

    /**
     * After a turn-in that offered no onward link, ask the giver whether the
     * quest is still open. It is the only authority that can tell a finished
     * quest from a multi-step one whose intermediate turn-in simply renders
     * without a link.
     *
     * Returns the step to re-enter, or null when the quest really is done.
     *
     * @param  Closure(string): void  $log
     */
    private function reEnterUnfinishedQuest(Closure $log, ?RunEventRecorder $events): ?int
    {
        $expected = $this->expectedSteps();

        if ($expected === null || $this->stepsCompleted >= $expected) {
            return null;
        }

        $log(sprintf(
            'Completed %d of %d known step(s) — checking whether the giver still offers the quest.',
            $this->stepsCompleted,
            $expected,
        ));

        try {
            $npc = $this->navigateToNpc();
            $quest = collect($this->questService->availableQuests($npc->spawnId, $npc->hash))
                ->firstWhere('questId', $this->config->questId);
        } catch (GameException $exception) {
            // Cannot ask right now. Reporting "complete" on a guess would be
            // worse than admitting we do not know.
            $events?->record(
                RunEventType::QuestSkipped,
                "Could not re-check quest {$this->config->questId} with its giver: {$exception->getMessage()}",
                ['quest_id' => $this->config->questId],
                RunEvent::LEVEL_WARNING,
            );

            return null;
        }

        if ($quest === null) {
            return null;
        }

        // The popup pointing back at a step we have already turned in means it
        // has nothing new for us; taking it would loop forever.
        if (in_array($quest->firstStepId, $this->completedStepIds, true)) {
            return null;
        }

        $log("Quest {$this->config->questId} is still open — continuing at step {$quest->firstStepId}.");

        return $quest->firstStepId;
    }

    /**
     * How many steps the catalog says this quest has. Advisory: a zero (the
     * column's default) means the crawl never recorded one, and stale counts
     * are corrected by the giver check that consumes this.
     */
    private function expectedSteps(): ?int
    {
        $this->expectedSteps ??= (int) Quest::where('game_quest_id', $this->config->questId)->value('steps_count');

        return $this->expectedSteps > 0 ? $this->expectedSteps : null;
    }

    /**
     * Farm the objective's target. Returns the nested farm summary (its end
     * reason distinguishes rage-out from "no way to progress"), or null when
     * no farmable mob is known or the farm could not start.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     */
    private function fulfill(
        QuestObjective $objective,
        Closure $log,
        ?Closure $signal,
        ?Closure $onBattle,
        ?Closure $ensureBuffs = null,
    ): ?MobRunSummary {
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
            // The buffs land here, once the quest has told us which mob to
            // farm — not during the walk to the giver or the dialog before it.
            return MobRunner::forCharacter($this->character, $config)
                ->run(log: $log, signal: $signal, onBattle: $onBattle, ensureBuffs: $ensureBuffs);
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

        $seeded = QuestItem::where('name', $itemName)->first()->source_mobs ?? [];

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

        // The giver is mapped and we are standing in its room — it simply is
        // not rendered this instant. Nothing about the quest is wrong.
        throw new TransientGameException("Quest-giver '{$this->config->npcName}' is not present in its room right now.");
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
