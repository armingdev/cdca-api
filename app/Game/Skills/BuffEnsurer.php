<?php

namespace App\Game\Skills;

use App\Game\Combat\StatsService;
use App\Game\Data\BuffEnsureResult;
use App\Game\Engine\RunEventRecorder;
use App\Game\Enums\RunEventType;
use App\Game\Exceptions\GameException;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\RunEvent;
use App\Models\Skill;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;

/**
 * Keeps a character's selected buffs up, and is cheap enough to call right
 * before every attack.
 *
 * This replaces casting everything once at run pickup. Buffs used to be spent
 * during the minutes of navigation and quest dialog that precede the first
 * blow; casting them at the point of combat instead means the duration is
 * spent fighting. Because the pass is idempotent, the same call also re-casts
 * whatever lapsed part-way through a long run.
 *
 * The cheap-call contract is what makes per-attack invocation viable: once a
 * pass has run, the ensurer knows the earliest moment any decision could
 * change, and until then every call is a clock comparison — no query, no
 * request.
 */
class BuffEnsurer
{
    /**
     * Re-cast a buff this long before it lapses, so a fight never starts on a
     * window that expires mid-swing.
     */
    private const int EXPIRY_MARGIN_MINUTES = 2;

    /**
     * How long to wait before retrying a skill that was ready but would not
     * go off (rage short, game refused). Rage arrives on the hourly tick, so
     * retrying sooner just burns requests.
     */
    private const int RETRY_FLOOR_MINUTES = 5;

    /**
     * Shortest gap between two full passes. Without it, a buff that is inside
     * its expiry margin but cannot be re-cast would re-sync before every
     * attack.
     */
    private const int MIN_RECHECK_SECONDS = 60;

    /** When the next pass may run. Null before the first pass. */
    private ?CarbonInterface $recheckAt = null;

    private ?BuffEnsureResult $lastResult = null;

    public function __construct(
        private readonly Character $character,
        private readonly SkillCaster $caster,
        private readonly SkillSyncService $sync,
        private readonly StatsService $stats,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(
            $character,
            SkillCaster::forCharacter($character),
            SkillSyncService::forCharacter($character),
            StatsService::forCharacter($character),
        );
    }

    /**
     * Make every selected skill (plus Circumspect when the run is gated on it)
     * an active buff, as far as the game allows right now.
     *
     * @param  Closure(string): void|null  $log
     */
    public function ensure(
        bool $includeCircumspect = false,
        ?Closure $log = null,
        ?RunEventRecorder $events = null,
    ): BuffEnsureResult {
        $log ??= fn (string $message) => null;

        // The whole cheap-call contract: after a pass, the ensurer knows the
        // earliest moment any of its decisions could change, and until then a
        // call is a clock comparison. The first call always does the work.
        if ($this->recheckAt !== null && $this->recheckAt->isFuture() && $this->lastResult !== null) {
            return $this->lastResult;
        }

        $states = $this->selectedStates($includeCircumspect);

        if ($states->isEmpty()) {
            return $this->remember(BuffEnsureResult::upToDate(), $states, $includeCircumspect);
        }

        $states = $this->refreshFromGame($states, $includeCircumspect, $log, $events);
        $result = $this->castPass($states, $log, $events);

        $log($result->summaryLine());

        return $this->remember($result, $this->selectedStates($includeCircumspect), $includeCircumspect);
    }

    /**
     * The character's cast-on-start selection, with Circumspect pulled to the
     * front when the run is gated on it — the gate decides whether the pass
     * was worth anything, so it must not be starved by a rage budget the
     * other buffs spent first.
     *
     * @return Collection<int, CharacterSkill>
     */
    private function selectedStates(bool $includeCircumspect): Collection
    {
        $states = CharacterSkill::with('skill')
            ->where('character_id', $this->character->id)
            ->where(function ($query) use ($includeCircumspect) {
                $query->where('cast_on_start', true)
                    ->when($includeCircumspect, fn ($q) => $q->orWhere('skill_id', Skill::CIRCUMSPECT_ID));
            })
            ->get();

        if (! $includeCircumspect) {
            return $states;
        }

        if (! $states->contains(fn (CharacterSkill $state) => $state->skill_id === Skill::CIRCUMSPECT_ID)) {
            $circumspect = Skill::find(Skill::CIRCUMSPECT_ID);

            if ($circumspect !== null) {
                $states->push(CharacterSkill::firstOrCreate([
                    'character_id' => $this->character->id,
                    'skill_id' => $circumspect->id,
                ])->setRelation('skill', $circumspect));
            }
        }

        return $states->sortByDesc(fn (CharacterSkill $state) => $state->skill_id === Skill::CIRCUMSPECT_ID)->values();
    }

    /**
     * Read the authoritative state before deciding anything: the five skill
     * tabs (levels + the Current Effects panel) and, for each selected skill
     * that is not already buffed, its recharge window.
     *
     * Every read is guarded. A parse blip here used to fail the whole run —
     * the run can still fight without a perfect skill picture, so a failed
     * sync degrades to the local estimate instead.
     *
     * @param  Collection<int, CharacterSkill>  $states
     * @param  Closure(string): void  $log
     * @return Collection<int, CharacterSkill>
     */
    private function refreshFromGame(
        Collection $states,
        bool $includeCircumspect,
        Closure $log,
        ?RunEventRecorder $events,
    ): Collection {
        $log('Reading skill state from the game…');

        try {
            $result = $this->sync->sync();

            if ($result->unreadableLevels !== []) {
                $events?->record(
                    RunEventType::SkillSyncFailed,
                    'Unrecognised level heading for '.implode(', ', $result->unreadableLevels).' — kept the stored levels.',
                    ['skills' => $result->unreadableLevels],
                    RunEvent::LEVEL_WARNING,
                );
            }
        } catch (GameException $exception) {
            $events?->record(
                RunEventType::SkillSyncFailed,
                "Could not read skills from the game: {$exception->getMessage()}",
                ['exception' => $exception::class],
                RunEvent::LEVEL_WARNING,
            );
        }

        $states = $this->selectedStates($includeCircumspect);

        foreach ($states as $state) {
            if ($state->isBuffActive() || ! $state->isCastable()) {
                continue;
            }

            try {
                $this->sync->refreshSkillInfo($state->skill);
            } catch (GameException $exception) {
                $events?->record(
                    RunEventType::SkillSyncFailed,
                    "Could not read {$state->skill->name}'s recharge: {$exception->getMessage()}",
                    ['skill_id' => $state->skill_id],
                    RunEvent::LEVEL_WARNING,
                );
            }
        }

        return $this->selectedStates($includeCircumspect);
    }

    /**
     * @param  Collection<int, CharacterSkill>  $states
     * @param  Closure(string): void  $log
     */
    private function castPass(Collection $states, Closure $log, ?RunEventRecorder $events): BuffEnsureResult
    {
        $rage = $this->currentRage();

        $cast = [];
        $skipped = [];
        $failed = [];

        foreach ($states as $state) {
            $entry = ['skill_id' => $state->skill_id, 'name' => $state->skill->name];

            if ($state->synced_at !== null && ! $state->isCastable()) {
                $skipped[] = $entry + ['reason' => BuffEnsureResult::REASON_UNTRAINED];
                $log("{$state->skill->name} not trained — skipping.");

                continue;
            }

            if ($state->isBuffActive()) {
                $skipped[] = $entry + ['reason' => BuffEnsureResult::REASON_ACTIVE];
                $log("{$state->skill->name} already active — skipping.");

                continue;
            }

            if ($state->isOnCooldown()) {
                $skipped[] = $entry + ['reason' => BuffEnsureResult::REASON_COOLDOWN];
                $log("{$state->skill->name} on cooldown — skipping.");

                continue;
            }

            $cost = $state->current_rage_cost ?? $state->skill->rage_cost;

            // A zero cost means no price has ever been read for this skill,
            // not that it is free — worth one attempt, since a refusal is
            // cheap and tells us more than a guess would. A real price we
            // cannot pay is different: skip it, but keep going, because a
            // cheaper skill further down the set may still fit.
            if ($cost > 0 && $rage !== null && $rage < $cost) {
                $skipped[] = $entry + ['reason' => BuffEnsureResult::REASON_RAGE];
                $log("{$state->skill->name} costs {$cost} rage, {$rage} held — skipping.");

                continue;
            }

            if ($this->caster->cast($state->skill)) {
                $cast[] = $entry;
                $rage = $rage !== null ? max(0, $rage - $cost) : null;
                $log("Cast {$state->skill->name}.");

                continue;
            }

            $failed[] = $entry + ['reason' => BuffEnsureResult::REASON_REFUSED];
            $log("Failed to cast {$state->skill->name} — the game did not confirm it.");
            $events?->record(
                RunEventType::SkillCastFailed,
                "Failed to cast {$state->skill->name} — the game did not confirm it.",
                $entry,
                RunEvent::LEVEL_WARNING,
            );
        }

        $circumspect = $this->freshCircumspectState();

        $result = new BuffEnsureResult(
            cast: $cast,
            skipped: $skipped,
            failed: $failed,
            circumspectActive: (bool) $circumspect?->isBuffActive(),
            circumspectExpiresAt: $circumspect?->buffEndsAt(),
            synced: true,
        );

        // One event per pass carrying the whole breakdown, rather than one per
        // skill: this is the record that answers "why did only five of nine
        // go off", and it stays a single row however often the pass runs.
        $events?->record(
            RunEventType::SkillCast,
            sprintf('Cast %d of %d selected skill(s).', count($cast), $states->count()),
            ['cast' => $cast, 'skipped' => $skipped, 'failed' => $failed],
            $failed === [] ? RunEvent::LEVEL_INFO : RunEvent::LEVEL_WARNING,
        );

        return $result;
    }

    /**
     * Rage on hand, straight from the game. A failure here is not fatal: the
     * pre-check simply falls back to attempting the cast.
     */
    private function currentRage(): ?int
    {
        try {
            return $this->stats->refresh()->rage;
        } catch (GameException) {
            return $this->character->rage;
        }
    }

    /**
     * The earliest moment a decision in this pass could change, so repeated
     * calls before then can return without touching the DB or the game.
     *
     * @param  Collection<int, CharacterSkill>  $states
     */
    private function remember(BuffEnsureResult $result, Collection $states, bool $includeCircumspect): BuffEnsureResult
    {
        $candidates = [];

        foreach ($states as $state) {
            if ($state->synced_at !== null && ! $state->isCastable()) {
                continue;
            }

            $buffEndsAt = $state->buffEndsAt();

            if ($buffEndsAt !== null && $buffEndsAt->isFuture()) {
                $candidates[] = $buffEndsAt->copy()->subMinutes(self::EXPIRY_MARGIN_MINUTES);

                continue;
            }

            $cooldownEndsAt = $state->cooldownEndsAt();

            if ($cooldownEndsAt !== null && $cooldownEndsAt->isFuture()) {
                $candidates[] = $cooldownEndsAt;

                continue;
            }

            // Castable right now but still not up: rage, or a refusal. Back
            // off rather than re-running the pass on the next attack.
            $candidates[] = now()->addMinutes(self::RETRY_FLOOR_MINUTES);
        }

        $next = $candidates === [] ? now()->addDay() : collect($candidates)->min();

        // A buff already inside its margin that nothing can fix — on cooldown,
        // or the game refused it — would otherwise put the next check in the
        // past and re-sync before every single attack. The floor bounds that
        // to one pass a minute at worst.
        $floor = now()->addSeconds(self::MIN_RECHECK_SECONDS);

        $this->recheckAt = $next->lessThan($floor) ? $floor : $next;
        $this->lastResult = $result;

        return $result;
    }

    /**
     * Circumspect straight from the DB — the in-memory states are stale the
     * moment the pass casts anything.
     */
    private function freshCircumspectState(): ?CharacterSkill
    {
        return CharacterSkill::with('skill')
            ->where('character_id', $this->character->id)
            ->where('skill_id', Skill::CIRCUMSPECT_ID)
            ->first();
    }
}
