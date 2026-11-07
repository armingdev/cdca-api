<?php

namespace App\Game\Quest;

use App\Game\Combat\StatsService;
use App\Game\Engine\QuestListProgress;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestListRunSummary;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Engine\RunEventRecorder;
use App\Game\Enums\RunEventType;
use App\Game\Enums\RunSignal;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\LoginFailedException;
use App\Game\Exceptions\ParseException;
use App\Game\Exceptions\QuestNotAvailableException;
use App\Game\Exceptions\SessionCollisionException;
use App\Game\Exceptions\TransientGameException;
use App\Game\World\GatedRooms;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Quest;
use App\Models\QuestList;
use App\Models\QuestListItem;
use App\Models\RunEvent;
use Closure;
use Illuminate\Support\Collection;

/**
 * Runs a run's quest lists back to back, each in order: for each quest, run
 * it via QuestRunner.
 *
 * A quest the character is not ready for is deferred, not walked to: below
 * its required level (after levelling from banked exp, when the run may), or
 * behind a prerequisite that was itself deferred. A list ordered top level
 * first used to send a level-83 character to every level-90 giver, read each
 * one's silence as "unavailable", and report the list complete with nothing
 * done. When a pass ends and the character has grown into a deferred quest,
 * another pass replays the lists running only the deferred ones.
 *
 * A quest the list cannot finish is skipped, not fatal — already completed,
 * giver unknown or unreachable, objective unfulfillable, or wanting an item
 * the game only sells. One bad entry at position 3 must never cost the
 * remaining thirty-seven. Only a whole-list condition ends the run: a stop or
 * pause, a lapsed Circumspect buff, or rage the character cannot rebuild
 * without waiting — and the last three park rather than stop.
 *
 * Where the run stands travels in a QuestListProgress, so a paused or parked
 * participant resumes mid-list, mid-pass, with its tallies intact; the
 * progress callback reports each change so the caller can persist it.
 */
class QuestListRunner
{
    /**
     * Parked retries one quest may cost the list before it is written off. A
     * page that would not parse, or an NPC not standing in its room this
     * minute, used to read as "skip this quest" — permanently, for the whole
     * run. Two retries separate a blip from a real dead end.
     */
    public const int MAX_QUEST_RETRIES = 2;

    /**
     * Passes one run may make: the full first one plus re-passes over the
     * deferred quests. Each re-pass needs the character to have grown into a
     * deferred quest, so this is only a backstop.
     */
    private const int MAX_PASSES = 10;

    /** Run-wide tallies, carried over from earlier jobs. */
    private int $completed = 0;

    private int $skipped = 0;

    /** This invocation's share of the tallies — what "this pass made progress" means to the job. */
    private int $completedNow = 0;

    private int $skippedNow = 0;

    /** @var array<string, int> skip reason => count */
    private array $skippedByReason = [];

    /** @var array<int, string> quest id => QuestListProgress::DEFERRED_* reason */
    private array $deferred = [];

    private int $pass = 1;

    /** @var list<int>|null quest ids this re-pass replays; null on the first pass */
    private ?array $retrying = null;

    private int $kills = 0;

    /** The list position the next cycle should start from. */
    private int $nextPosition = 0;

    /** Which of the run's lists $nextPosition belongs to. */
    private int $listIndex = 0;

    /** The list being played — tags skip/complete events, whose positions are per list. */
    private ?int $currentListId = null;

    /** Parked retries already spent on the quest at $nextPosition. */
    private int $questRetries = 0;

    private ?RunEventRecorder $events = null;

    private ?int $runId = null;

    /** Quest ids this character has already settled, skipped without walking. */
    /** @var list<int> */
    private array $skippableQuestIds = [];

    /** Whether the level has been read from the game this run, rather than trusted from the database. */
    private bool $levelConfirmed = false;

    /** The exp held when the game last refused a level-up: no point asking again until it moves. */
    private ?int $levelUpRefusedAtExp = null;

    public function __construct(
        private readonly Character $character,
        private readonly QuestListRunConfig $config,
        private readonly QuestProgressLedger $ledger,
        private readonly StatsService $stats,
        private readonly GatedRooms $gates,
    ) {}

    public static function forCharacter(Character $character, QuestListRunConfig $config): self
    {
        return new self(
            $character,
            $config,
            app(QuestProgressLedger::class),
            StatsService::forCharacter($character),
            app(GatedRooms::class),
        );
    }

    /**
     * Play the run's lists back to back from where $from stands, then replay
     * deferred quests while the character keeps growing into them. Quests
     * completed in an earlier list are recorded in the ledger as they finish,
     * so a later list that repeats them skips them like any other settled
     * quest.
     *
     * @param  Closure(string): void|null  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs  just-in-time buff top-up, forwarded to each quest
     * @param  QuestListProgress  $from  where a resumed run left off; a fresh run starts at the top
     * @param  Closure(QuestListProgress): void|null  $onProgress  after each settled item, each finished list and each new pass
     * @param  RunEventRecorder|null  $events  durable log for skip/defer/complete decisions
     * @param  int|null  $runId  provenance for anything written to the progress ledger
     */
    public function run(
        ?Closure $log = null,
        ?Closure $signal = null,
        ?Closure $onBattle = null,
        ?Closure $ensureBuffs = null,
        QuestListProgress $from = new QuestListProgress,
        ?Closure $onProgress = null,
        ?RunEventRecorder $events = null,
        ?int $runId = null,
    ): QuestListRunSummary {
        $this->resumeFrom($from);
        $this->events = $events;
        $this->runId = $runId;
        $log ??= fn (string $message) => null;

        $listIds = $this->config->questListIds;
        $lists = QuestList::with('items.quest')->whereIn('id', $listIds)->get()->keyBy('id');

        if ($lists->isEmpty()) {
            throw new GameException('Quest list #'.implode(', #', $listIds).' not found.');
        }

        $startListIndex = $from->listIndex;
        $startPosition = $from->position;

        while (true) {
            $outcome = $this->playLists($listIds, $lists, $startListIndex, $startPosition, $log, $signal, $onBattle, $ensureBuffs, $onProgress);

            if ($outcome !== null) {
                return $outcome;
            }

            if (! $this->startNextPass($this->questsOf($lists), $log)) {
                break;
            }

            $startListIndex = 0;
            $startPosition = 0;
            $onProgress?->__invoke($this->progress());
        }

        return $this->summary(
            completed: true,
            reason: $this->finishedReason(count($listIds), $this->questsOf($lists)),
            endReason: RunEndReason::Completed,
        );
    }

    private function resumeFrom(QuestListProgress $from): void
    {
        $this->listIndex = $from->listIndex;
        $this->nextPosition = $from->position;
        $this->questRetries = $from->questRetries;
        $this->completed = $from->completed;
        $this->skipped = $from->skipped;
        $this->skippedByReason = $from->skippedByReason;
        $this->deferred = $from->deferred;
        $this->pass = $from->pass;
        $this->retrying = $from->retrying;
    }

    /**
     * One pass over every list, from ($startListIndex, $startPosition). Null
     * when it ran to the end; a summary when the run has to stop or park.
     *
     * @param  list<int>  $listIds
     * @param  Collection<int, QuestList>  $lists
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     * @param  Closure(QuestListProgress): void|null  $onProgress
     */
    private function playLists(
        array $listIds,
        Collection $lists,
        int $startListIndex,
        int $startPosition,
        Closure $log,
        ?Closure $signal,
        ?Closure $onBattle,
        ?Closure $ensureBuffs,
        ?Closure $onProgress,
    ): ?QuestListRunSummary {
        for ($index = $startListIndex; $index < count($listIds); $index++) {
            $this->listIndex = $index;
            $position = $index === $startListIndex ? $startPosition : 0;
            $list = $lists->get($listIds[$index]);

            if ($list === null) {
                // Deleted after the run started: the remaining lists still run.
                $log("Quest list #{$listIds[$index]} no longer exists — skipping it.");
                $this->events?->record(RunEventType::Info, "Quest list #{$listIds[$index]} no longer exists — skipped.", [
                    'quest_list_id' => $listIds[$index],
                    'list_index' => $index,
                ], RunEvent::LEVEL_WARNING);
            } else {
                $outcome = $this->runList($list, $index, count($listIds), $position, $log, $signal, $onBattle, $ensureBuffs, $onProgress);

                if ($outcome !== null) {
                    return $outcome;
                }
            }

            // Persist the move to the next list, so a resume never re-enters
            // the tail of a list that is already done. After the last list
            // the cursor stays past its end, as a single-list run always had.
            if ($index + 1 < count($listIds)) {
                $this->listIndex = $index + 1;
                $this->nextPosition = 0;
                $onProgress?->__invoke($this->progress());
            }
        }

        return null;
    }

    /**
     * One list of the pass, from $startPosition. Null when it ran to its end;
     * a summary when the whole run has to stop or park on one of its quests.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     * @param  Closure(QuestListProgress): void|null  $onProgress
     */
    private function runList(
        QuestList $list,
        int $index,
        int $listCount,
        int $startPosition,
        Closure $log,
        ?Closure $signal,
        ?Closure $onBattle,
        ?Closure $ensureBuffs,
        ?Closure $onProgress,
    ): ?QuestListRunSummary {
        $this->currentListId = $list->id;
        $this->nextPosition = $startPosition;

        // A re-pass plays only the quests it was started for; the rest of the
        // list was settled one way or another on the first pass.
        $items = $list->items
            ->where('position', '>=', $startPosition)
            ->when($this->retrying !== null, fn (Collection $items) => $items->whereIn('quest_id', $this->retrying))
            ->values();

        // Read once per list, before walking anywhere: what this character has
        // already settled with the game. Re-deriving it by visiting all 200
        // givers is exactly the cost this avoids.
        $this->skippableQuestIds = $this->ledger->skippableQuestIds(
            $this->character,
            $items->pluck('quest_id'),
        );

        $message = sprintf(
            "Running quest list '%s'%s%s (%d quest(s)%s).",
            $list->name,
            $listCount > 1 ? ' — list '.($index + 1)." of {$listCount}" : '',
            $this->pass > 1 ? " — pass {$this->pass}, deferred quests only" : '',
            $items->count(),
            $startPosition > 0 ? ", resuming from position {$startPosition}" : '',
        );
        $log($message);

        if ($listCount > 1) {
            $this->events?->record(RunEventType::Info, $message, [
                'quest_list_id' => $list->id,
                'list_index' => $index,
                'list_count' => $listCount,
                'pass' => $this->pass,
            ]);
        }

        if ($this->skippableQuestIds !== [] && $this->retrying === null) {
            $recorded = count($this->skippableQuestIds);
            $log("Skipping {$recorded} quest(s) already recorded for this character.");
            $this->events?->record(
                RunEventType::QuestSkipped,
                "Skipping {$recorded} quest(s) already recorded for this character.",
                ['reason' => 'recorded', 'count' => $recorded, 'quest_ids' => $this->skippableQuestIds, 'quest_list_id' => $list->id],
            );
        }

        foreach ($items as $item) {
            $this->nextPosition = $item->position;

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

            if ($control === RunSignal::WorkerShutdown) {
                return $this->summary(completed: false, reason: 'Worker restarting.', endReason: RunEndReason::WorkerShutdown);
            }

            $outcome = $this->runQuest($item, $log, $signal, $onBattle, $ensureBuffs);

            if ($outcome !== null) {
                return $outcome;
            }

            // Settling an item clears the retry budget: it belongs to the
            // quest that was stuck, not to the list.
            $this->nextPosition = $item->position + 1;
            $this->questRetries = 0;
            $onProgress?->__invoke($this->progress());
        }

        if ($this->pass === 1) {
            $this->recordDeferrals($list);
        }

        return null;
    }

    /**
     * Run one list item. Returns a terminal summary when the list should stop,
     * or null to continue to the next quest.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     * @param  Closure(): void|null  $ensureBuffs
     */
    private function runQuest(
        QuestListItem $item,
        Closure $log,
        ?Closure $signal,
        ?Closure $onBattle,
        ?Closure $ensureBuffs = null,
    ): ?QuestListRunSummary {
        $quest = $item->quest;

        if (in_array($quest?->id, $this->skippableQuestIds, true)) {
            // Already settled for this character — the summary event above
            // covers the whole batch, so this one stays silent.
            $this->countSkip('recorded');

            return null;
        }

        if ($quest->giver === null) {
            $this->skip($item, 'no_giver', 'No known giver.', $log);

            return null;
        }

        $deferral = $this->deferralFor($quest, $log);

        if ($deferral !== null) {
            $this->deferred[$quest->id] = $deferral;
            $log("Deferring {$item->displayName()}: ".$this->deferralLine($quest, $deferral));

            return null;
        }

        $log("→ {$item->displayName()} (quest {$quest->game_quest_id} via {$quest->giver}).");

        $questConfig = new QuestRunConfig(
            npcName: $quest->giver,
            questId: $quest->game_quest_id,
            stopRage: $this->config->stopRage,
            levelUp: $this->config->levelUp,
            smart: $this->config->smart,
            respawnWaitSeconds: $this->config->respawnWaitSeconds,
            skipShardQuests: $this->config->skipShardQuests,
        );

        try {
            $summary = QuestRunner::forCharacter($this->character, $questConfig)
                ->run(log: $log, signal: $signal, onBattle: $onBattle, ensureBuffs: $ensureBuffs, events: $this->events);
        } catch (QuestNotAvailableException) {
            // Not in the character's tracker and offered by nobody, at a level
            // the character meets: finished long ago, or behind a prerequisite.
            // A quest that is merely *under way* never reaches here —
            // QuestRunner raises a plain GameException for that, so no guess
            // lands in the ledger.
            $this->skip($item, 'not_available', 'No mob offers this quest and it is not in progress.', $log);
            // Remember it, so the next run does not walk here to be told the
            // same thing. Cleared once its prerequisite is seen to finish.
            $this->ledger->recordUnavailable($this->character, $quest, $this->runId);

            return null;
        } catch (SessionCollisionException|LoginFailedException $exception) {
            // Session-level failures are the job's to recover from, not a
            // property of this quest — never swallow them into a skip.
            throw $exception;
        } catch (TransientGameException|ParseException $exception) {
            // A page that would not parse, or an NPC that happens not to be
            // standing in its room, says nothing about whether this character
            // can do the quest. Park and come back to it; only a quest that
            // fails this way repeatedly is written off.
            return $this->retryOrSkip($item, $exception->getMessage(), $log);
        } catch (GameException $exception) {
            // An unmapped giver or a world with no path to it is a real
            // property of this quest, and waiting will not change it — but the
            // remaining quests are still perfectly runnable. (A server outage
            // is not a GameException and passes straight through to the job.)
            $this->skip($item, 'unreachable', $exception->getMessage(), $log);

            return null;
        }

        $this->kills += $summary->kills;

        if ($summary->endReason === RunEndReason::ExternalStop) {
            return $this->summary(completed: false, reason: 'Stop requested.', endReason: RunEndReason::ExternalStop);
        }

        if ($summary->endReason === RunEndReason::ExternalPause) {
            return $this->summary(completed: false, reason: 'Pause requested.', endReason: RunEndReason::ExternalPause);
        }

        if ($summary->endReason === RunEndReason::CircumspectExpired) {
            return $this->summary(completed: false, reason: $summary->stopReason, endReason: RunEndReason::CircumspectExpired);
        }

        if ($summary->endReason === RunEndReason::WorkerShutdown) {
            return $this->summary(completed: false, reason: $summary->stopReason, endReason: RunEndReason::WorkerShutdown);
        }

        // Nothing the list can do about this quest, and everything it can do
        // about the next one: skip rather than take the whole list down.
        if ($summary->endReason === RunEndReason::RequiresPurchasedItem
            || $summary->endReason === RunEndReason::Stuck
            || $summary->endReason === RunEndReason::Outmatched
        ) {
            $this->skip($item, $summary->endReason->value, $summary->stopReason, $log);

            return null;
        }

        if (! $summary->completed) {
            // A parked quest resumes where it stands, so the wrapper must not
            // read "Stopped".
            $verb = in_array($summary->endReason, [
                RunEndReason::TargetsDepleted,
                RunEndReason::RageInsufficient,
                RunEndReason::RageExhausted,
            ], true) ? 'Waiting' : 'Stopped';

            return $this->summary(
                completed: false,
                reason: "{$verb} on {$item->displayName()}: {$summary->stopReason}",
                endReason: $summary->endReason,
            );
        }

        $this->completed++;
        $this->completedNow++;
        $this->questRetries = 0;
        $this->ledger->recordCompleted($this->character, $quest, $this->runId);
        // A finished quest may have handed out the very key a gate wanted.
        $this->gates->forget($this->character);

        $this->events?->record(RunEventType::QuestCompleted, "Completed {$item->displayName()}.", [
            'quest_id' => $quest->game_quest_id,
            'position' => $item->position,
            'quest_list_id' => $this->currentListId,
        ]);

        return null;
    }

    /**
     * Why the character is not ready for this quest yet, or null when it is.
     */
    private function deferralFor(Quest $quest, Closure $log): ?string
    {
        if ($quest->required_level !== null && ! $this->reachesLevel($quest->required_level, $log)) {
            return QuestListProgress::DEFERRED_LEVEL;
        }

        if ($quest->prerequisite_quest_id !== null && isset($this->deferred[$quest->prerequisite_quest_id])) {
            return QuestListProgress::DEFERRED_PREREQUISITE;
        }

        return null;
    }

    /**
     * Whether the character is — or, when the run may level, can now be
     * levelled to — $required. The stored level can lag the game, so it is
     * read once before any quest is deferred on it; later fights keep the
     * shared character record current.
     */
    private function reachesLevel(int $required, Closure $log): bool
    {
        if ($this->character->level >= $required) {
            return true;
        }

        if (! $this->levelConfirmed) {
            $this->stats->refresh();
            $this->levelConfirmed = true;
        }

        if (! $this->config->levelUp && ! $this->config->smart) {
            return $this->character->level >= $required;
        }

        $before = $this->character->level;

        // One attempt per missing level at most; a refusal (not enough exp)
        // holds until the character's exp moves.
        for ($attempts = $required - $before; $attempts > 0 && $this->character->level < $required; $attempts--) {
            $refusedAtThisExp = $this->levelUpRefusedAtExp !== null && $this->levelUpRefusedAtExp === $this->character->exp;

            if ($refusedAtThisExp || ! $this->stats->tryLevelUp()) {
                $this->levelUpRefusedAtExp = $this->character->exp;

                break;
            }
        }

        if ($this->character->level > $before) {
            $log("Levelled up to {$this->character->level}.");
        }

        return $this->character->level >= $required;
    }

    private function deferralLine(Quest $quest, string $reason): string
    {
        return $reason === QuestListProgress::DEFERRED_LEVEL
            ? "needs level {$quest->required_level}, character is {$this->character->level}."
            : 'waits on '.($quest->prerequisite !== null ? "'{$quest->prerequisite}'" : 'its prerequisite').', which is itself deferred.';
    }

    /**
     * One event per list for everything it put off, rather than one per quest:
     * a level-83 character on an endgame list defers dozens at a time.
     */
    private function recordDeferrals(QuestList $list): void
    {
        $quests = $list->items->pluck('quest')->filter()->keyBy('id');
        $deferred = array_intersect_key($this->deferred, $quests->all());

        if ($deferred === []) {
            return;
        }

        $levels = $quests->only(array_keys($deferred, QuestListProgress::DEFERRED_LEVEL, true))->pluck('required_level');
        $behindPrerequisite = count(array_keys($deferred, QuestListProgress::DEFERRED_PREREQUISITE, true));

        $this->events?->record(
            RunEventType::QuestDeferred,
            sprintf(
                "Deferred %d quest(s) in '%s' until the character is ready%s%s.",
                count($deferred),
                $list->name,
                $levels->isEmpty() ? '' : ": {$levels->count()} need level ".$this->levelRange($levels),
                $behindPrerequisite === 0 ? '' : ($levels->isEmpty() ? ': ' : ', ')."{$behindPrerequisite} wait on a deferred prerequisite",
            ),
            [
                'quest_list_id' => $list->id,
                'level' => $this->character->level,
                'quest_ids' => $quests->only(array_keys($deferred))->pluck('game_quest_id')->values()->all(),
            ],
        );
    }

    /**
     * After a full pass: replay the deferred quests if the character has grown
     * into at least one of them — by playing the list, or by a level-up the
     * run may now afford. Nothing to gain otherwise, so the run ends.
     *
     * @param  Collection<int, Quest>  $quests  every quest of the run's lists, by id
     */
    private function startNextPass(Collection $quests, Closure $log): bool
    {
        if ($this->deferred === [] || $this->pass >= self::MAX_PASSES) {
            return false;
        }

        $lowest = $quests->only(array_keys($this->deferred, QuestListProgress::DEFERRED_LEVEL, true))
            ->pluck('required_level')
            ->min();

        if ($lowest === null) {
            return false;
        }

        // One read per finished pass: the level may have moved in ways this
        // run did not see (levelled for rage mid-fight, or by hand).
        $this->stats->refresh();

        if (! $this->reachesLevel($lowest, $log)) {
            return false;
        }

        $this->retrying = array_keys($this->deferred);
        $this->deferred = [];
        $this->pass++;
        $this->listIndex = 0;
        $this->nextPosition = 0;
        $this->questRetries = 0;

        $message = sprintf(
            'Pass %d: the character is level %d now, so %d deferred quest(s) get another try.',
            $this->pass,
            $this->character->level,
            count($this->retrying),
        );
        $log($message);
        $this->events?->record(RunEventType::Info, $message, ['pass' => $this->pass, 'level' => $this->character->level]);

        return true;
    }

    /**
     * The end of the run, told straight: how much got done, how much waits for
     * the character to level, how much was written off.
     *
     * @param  Collection<int, Quest>  $quests
     */
    private function finishedReason(int $listCount, Collection $quests): string
    {
        $parts = ["{$this->completed} completed"];

        if ($this->deferred !== []) {
            $levels = $quests->only(array_keys($this->deferred, QuestListProgress::DEFERRED_LEVEL, true))->pluck('required_level');
            $parts[] = count($this->deferred).' deferred'.($levels->isEmpty()
                ? ' behind unfinished prerequisites'
                : ' until level '.$this->levelRange($levels));
        }

        if ($this->skipped > 0) {
            $parts[] = "{$this->skipped} skipped";
        }

        $what = $listCount > 1 ? 'All quest lists' : 'Quest list';

        return ($this->deferred === [] ? "{$what} complete: " : "{$what} done for now: ").implode(', ', $parts).'.';
    }

    /**
     * @param  Collection<int, int>  $levels
     */
    private function levelRange(Collection $levels): string
    {
        return $levels->min() === $levels->max() ? (string) $levels->min() : "{$levels->min()}–{$levels->max()}";
    }

    /**
     * @param  Collection<int, QuestList>  $lists
     * @return Collection<int, Quest>
     */
    private function questsOf(Collection $lists): Collection
    {
        return $lists->flatMap(fn (QuestList $list) => $list->items->pluck('quest'))->filter()->keyBy('id');
    }

    /**
     * A failure that is not the quest's fault: park the list on this item so
     * the same quest is retried, until the retry budget runs out.
     *
     * @param  Closure(string): void  $log
     */
    private function retryOrSkip(QuestListItem $item, string $message, Closure $log): ?QuestListRunSummary
    {
        if ($this->questRetries >= self::MAX_QUEST_RETRIES) {
            $attempts = $this->questRetries;
            $this->questRetries = 0;
            $this->skip($item, 'transient_exhausted', $message, $log, "Skipping {$item->displayName()} after {$attempts} retries: {$message}");

            return null;
        }

        $this->questRetries++;

        $this->events?->record(
            RunEventType::QuestRetryScheduled,
            "Retrying {$item->displayName()} (attempt {$this->questRetries}): {$message}",
            [
                'quest_id' => $item->quest->game_quest_id,
                'position' => $item->position,
                'quest_list_id' => $this->currentListId,
                'attempt' => $this->questRetries,
            ],
            RunEvent::LEVEL_WARNING,
        );

        return $this->summary(
            completed: false,
            reason: "Paused on {$item->displayName()}: {$message}",
            endReason: RunEndReason::TransientError,
        );
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function skip(QuestListItem $item, string $reason, string $message, Closure $log, ?string $logLine = null): void
    {
        $this->countSkip($reason);
        $log($logLine ?? "Skipping {$item->displayName()}: {$message}");

        $this->events?->record(
            RunEventType::QuestSkipped,
            "Skipped {$item->displayName()}: {$message}",
            [
                'quest_id' => $item->quest?->game_quest_id,
                'position' => $item->position,
                'quest_list_id' => $this->currentListId,
                'reason' => $reason,
            ],
            RunEvent::LEVEL_WARNING,
        );
    }

    private function countSkip(string $reason): void
    {
        $this->skipped++;
        $this->skippedNow++;
        $this->skippedByReason[$reason] = ($this->skippedByReason[$reason] ?? 0) + 1;
    }

    private function progress(): QuestListProgress
    {
        return new QuestListProgress(
            listIndex: $this->listIndex,
            position: $this->nextPosition,
            questRetries: $this->questRetries,
            completed: $this->completed,
            skipped: $this->skipped,
            skippedByReason: $this->skippedByReason,
            deferred: $this->deferred,
            pass: $this->pass,
            retrying: $this->retrying,
        );
    }

    private function summary(bool $completed, string $reason, RunEndReason $endReason): QuestListRunSummary
    {
        return new QuestListRunSummary(
            completed: $completed,
            questsCompleted: $this->completedNow,
            questsSkipped: $this->skippedNow,
            kills: $this->kills,
            stopReason: $reason,
            endReason: $endReason,
            progress: $this->progress(),
        );
    }
}
