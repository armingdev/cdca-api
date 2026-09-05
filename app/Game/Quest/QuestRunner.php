<?php

namespace App\Game\Quest;

use App\Game\Combat\StatsService;
use App\Game\Data\ActiveQuest;
use App\Game\Data\AvailableQuest;
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
 * source mobs) and re-view the step.
 *
 * Where the character *already* stands in a quest comes from the tracker
 * (`world_questHelper.php`), never from the giver. The giver's popup lists a
 * quest only while its current step belongs to that mob, so a quest several
 * steps in — whose step has moved to another mob — reads as "not offered"
 * there, and taking that silence at face value skipped quests wholesale.
 */
class QuestRunner
{
    private const int MAX_COMPASS_STEPS = 150;

    /**
     * How many locate cycles may pass without a step turned in or a kill
     * landed before the quest is called stuck. Productive cycles never count
     * against it, so a long quest runs as long as it keeps moving.
     */
    private const int MAX_IDLE_CYCLES = 3;

    private int $stepsCompleted = 0;

    private int $expGained = 0;

    private int $kills = 0;

    /** Steps already turned in this pass, so a re-entry cannot loop on one. */
    /** @var list<int> */
    private array $completedStepIds = [];

    private ?int $expectedSteps = null;

    /**
     * The mob whose dialog is being worked through. It starts as the catalog
     * giver and moves with the quest as the tracker hands us on.
     */
    private string $currentNpcName;

    public function __construct(
        private readonly Character $character,
        private readonly QuestRunConfig $config,
        private readonly QuestService $questService,
        private readonly Navigator $navigator,
        private readonly RoomGraph $graph,
        private readonly StatsService $stats,
        private readonly PurchasedQuestItems $purchasedItems,
        private readonly ?TeleportService $teleports = null,
    ) {
        $this->currentNpcName = $config->npcName;
    }

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

        $idleCycles = 0;
        $lastSignature = null;

        while (true) {
            $control = $this->externalVerdict($signal);

            if ($control !== null) {
                return $control;
            }

            $stepsBefore = $this->stepsCompleted;
            $tracked = $this->trackedQuest();
            $signature = $this->trackerSignature($tracked);

            // Nothing left to work and the tracker has forgotten it: the last
            // turn-in ended the quest.
            if ($tracked === null && $this->stepsCompleted > 0) {
                $events?->record(RunEventType::QuestCompleted, "Quest {$this->config->questId} complete.", [
                    'quest_id' => $this->config->questId,
                    'steps_completed' => $this->stepsCompleted,
                    'steps_expected' => $this->expectedSteps(),
                ]);

                return $this->summary(completed: true, reason: 'Quest complete.', endReason: RunEndReason::Completed);
            }

            if ($tracked !== null && $tracked->unmetObjectives() !== []) {
                // Farm straight off the tracker: the step's mob has nothing to
                // say until the counts are in, so walking to it first only
                // spends the trip twice.
                $outcome = $this->farmObjectives(
                    $tracked->unmetObjectives(),
                    $tracked->stepId,
                    $log,
                    $signal,
                    $onBattle,
                    $ensureBuffs,
                    $events,
                );

                if ($outcome instanceof QuestRunSummary) {
                    return $outcome;
                }
            } else {
                $entry = $this->enterQuest($tracked, $log);

                // Being handed back a step we already turned in means the
                // game is not registering the turn-in; following it would
                // finish the same step forever.
                if (in_array($entry->firstStepId, $this->completedStepIds, true)) {
                    return $this->summary(
                        completed: false,
                        reason: "Step {$entry->firstStepId} was turned in but the game keeps offering it.",
                        endReason: RunEndReason::Stuck,
                    );
                }

                $outcome = $this->workSteps($entry, $log, $signal, $onBattle, $ensureBuffs, $events);

                if ($outcome instanceof QuestRunSummary) {
                    return $outcome;
                }
            }

            // Kills alone are not progress: a farm that lands wins the quest
            // never counts would otherwise spin here forever. Only a step
            // turned in, or the tracker's own counters moving, resets this.
            $moved = $this->stepsCompleted > $stepsBefore || $signature !== $lastSignature;
            $idleCycles = $moved ? 0 : $idleCycles + 1;
            $lastSignature = $signature;

            if ($idleCycles >= self::MAX_IDLE_CYCLES) {
                return $this->summary(
                    completed: false,
                    reason: "Quest {$this->config->questId} stopped making progress.",
                    endReason: RunEndReason::Stuck,
                );
            }
        }
    }

    /**
     * Work the dialog forward from an entry point, step by step. Returns a
     * summary when the quest must end, or null when the dialog has run out of
     * links and the caller should re-locate from the tracker.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     */
    private function workSteps(
        AvailableQuest $entry,
        Closure $log,
        ?Closure $signal,
        ?Closure $onBattle,
        ?Closure $ensureBuffs,
        ?RunEventRecorder $events,
    ): ?QuestRunSummary {
        $npcId = $entry->npcId;
        $stepId = $entry->firstStepId;
        $sendQuestId = true;
        $reviewedStalledStep = false;

        while (true) {
            $control = $this->externalVerdict($signal);

            if ($control !== null) {
                return $control;
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
                    // remaining step. Hand back to the tracker, which knows
                    // whether the quest is finished and, if not, which mob
                    // holds the next step.
                    return null;
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
     * Work the objectives a step *page* is showing, then walk back to its mob
     * so the loop can re-view it.
     *
     * Returns a summary when the quest must end, or the new "already re-viewed
     * a stalled step" flag when the loop should re-view the step and carry on.
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

        $outcome = $this->farmObjectives($unmet, $stepId, $log, $signal, $onBattle, $ensureBuffs, $events);

        if ($outcome instanceof QuestRunSummary) {
            return $outcome;
        }

        $this->navigateToNpc($this->currentNpcName);

        return false;
    }

    /**
     * Farm every unmet objective in the list, then report whether the pass
     * moved at all. Returns a summary when the quest must end, or null when
     * something was killed and the caller should look again.
     *
     * Looping matters: a step that wants ten of mob A *and* ten of mob B used
     * to inspect only the first entry, so B's mob being unfarmable condemned
     * the whole quest after A had been farmed at full rage cost.
     *
     * @param  list<QuestObjective>  $unmet
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     */
    private function farmObjectives(
        array $unmet,
        int $stepId,
        Closure $log,
        ?Closure $signal,
        ?Closure $onBattle,
        ?Closure $ensureBuffs,
        ?RunEventRecorder $events,
    ): ?QuestRunSummary {
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

        return null;
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
     * This quest as the tracker currently reports it, or null when the
     * character has it neither started nor unfinished.
     */
    private function trackedQuest(): ?ActiveQuest
    {
        foreach ($this->questService->activeQuests() as $quest) {
            if ($quest->questId === $this->config->questId) {
                return $quest;
            }
        }

        return null;
    }

    /**
     * A fingerprint of where the tracker says the character stands, so a cycle
     * that changed nothing can be told from one that advanced a counter.
     */
    private function trackerSignature(?ActiveQuest $tracked): string
    {
        if ($tracked === null) {
            return 'absent';
        }

        return $tracked->stepId.':'.implode(',', array_map(
            fn (QuestObjective $objective) => sprintf(
                '%s=%d/%d%s',
                $objective->target,
                $objective->current,
                $objective->required,
                $objective->complete ? '!' : '',
            ),
            $tracked->objectives,
        ));
    }

    /**
     * Walk to a mob that will talk to us about this quest and open its dialog.
     *
     * The tracker names the mob holding the current step, which is the only
     * reliable answer once a quest is under way — its step moves from mob to
     * mob as the chain advances, and the original giver falls silent the
     * moment it does. The giver is tried too, both as the entry point for a
     * quest not yet started and as a fallback.
     *
     * @param  Closure(string): void  $log
     *
     * @throws QuestNotAvailableException when nobody offers a quest that is not under way
     * @throws GameException when a quest that *is* under way cannot be reached
     */
    private function enterQuest(?ActiveQuest $tracked, Closure $log): AvailableQuest
    {
        $candidates = array_values(array_unique(array_filter([
            $tracked?->talkTarget(),
            $this->config->npcName,
        ])));

        $blocker = null;

        foreach ($candidates as $npcName) {
            try {
                $sighting = $this->navigateToNpc($npcName);
            } catch (TransientGameException $exception) {
                // The mob is mapped and we are standing in its room; it is
                // simply not rendered this instant. Retrying is the whole
                // point of the transient tier — never downgrade it to a skip.
                throw $exception;
            } catch (GameException $exception) {
                // Unmapped or unreachable. A sibling candidate may still work,
                // so remember the reason and try the next one.
                $blocker ??= $exception;
                $log("Cannot reach '{$npcName}': {$exception->getMessage()}");

                continue;
            }

            $quest = collect($this->questService->availableQuests($sighting->spawnId, $sighting->hash))
                ->firstWhere('questId', $this->config->questId);

            if ($quest !== null) {
                $this->currentNpcName = $npcName;
                $log($tracked === null
                    ? "Accepting quest {$this->config->questId} from {$npcName}."
                    : "Continuing quest {$this->config->questId} at {$npcName}, step {$quest->firstStepId}.");

                return $quest;
            }

            $log("'{$npcName}' does not offer quest {$this->config->questId}.");
        }

        if ($blocker !== null) {
            throw $blocker;
        }

        // A quest the tracker still lists is *in progress*, whatever the mobs
        // say. Calling it unavailable would write that guess to the ledger and
        // skip the quest on every future run — the exact failure this whole
        // path exists to prevent.
        if ($tracked !== null) {
            throw new GameException(sprintf(
                'Quest %d is in progress at step %d but no mob offers it (tried %s).',
                $this->config->questId,
                $tracked->stepId,
                implode(', ', $candidates),
            ));
        }

        throw new QuestNotAvailableException($this->config->questId, $this->config->npcName);
    }

    /**
     * The caller's stop/pause verdict, when it has one.
     *
     * @param  Closure(): RunSignal|null  $signal
     */
    private function externalVerdict(?Closure $signal): ?QuestRunSummary
    {
        return match ($signal !== null ? $signal() : RunSignal::None) {
            RunSignal::Stop => $this->summary(false, 'Stop requested.', RunEndReason::ExternalStop),
            RunSignal::Pause => $this->summary(false, 'Pause requested.', RunEndReason::ExternalPause),
            RunSignal::CircumspectExpired => $this->summary(false, 'Circumspect expired.', RunEndReason::CircumspectExpired),
            RunSignal::None => null,
        };
    }

    /**
     * How many steps the catalog says this quest has. Advisory only — it just
     * annotates the completion event, and a zero means the crawl never
     * recorded one.
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
     * Walk to the named mob's room and return its sighting (for spawn id +
     * hash). Cheap when already there.
     *
     * Mobs are found by name, never by the game's mob id: the seeded ids are
     * not unique (184 names share one) and do not always match the live world,
     * whereas a talkable mob's name does.
     */
    private function navigateToNpc(string $npcName): MobSighting
    {
        $rooms = Mob::where('name', $npcName)
            ->with('rooms:id')
            ->get()
            ->flatMap(fn (Mob $mob) => $mob->rooms->pluck('id'))
            ->unique()
            ->values();

        if ($rooms->isEmpty()) {
            throw new GameException("Quest-giver '{$npcName}' is not in the mapped world.");
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
                throw new GameException("No path to quest-giver '{$npcName}'.");
            }

            $blob = $this->teleports !== null
                ? $this->teleports->travel($plan)
                : $this->navigator->walk($plan->walkPath);
        }

        foreach ($blob->mobs as $sighting) {
            if ($sighting->name === $npcName) {
                return $sighting;
            }
        }

        // The giver is mapped and we are standing in its room — it simply is
        // not rendered this instant. Nothing about the quest is wrong.
        throw new TransientGameException("Quest-giver '{$npcName}' is not present in its room right now.");
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
