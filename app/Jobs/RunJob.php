<?php

namespace App\Jobs;

use App\Game\Auth\LoginService;
use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\RunEventRecorder;
use App\Game\Enums\BattleOutcome;
use App\Game\Enums\CharacterActivity;
use App\Game\Enums\RunEventType;
use App\Game\Enums\RunSignal;
use App\Game\Enums\RunStatus;
use App\Game\Exceptions\SessionCollisionException;
use App\Game\GameClock;
use App\Game\Skills\BuffEnsurer;
use App\Game\Skills\CircumspectGate;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\RunParticipant;
use App\Models\Skill;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Base for one-character run jobs. Owns the participant lifecycle (pre-pickup
 * stop/pause, running → parked/finished transitions, tally callbacks, failure
 * handling) and the long-lived queue placement. Subclasses only drive their
 * engine. Lives for the whole run (possibly hours) on the redis-runs
 * connection whose retry_after exceeds the supervisor timeout, so a live run
 * is never re-dispatched.
 */
abstract class RunJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Engine iterations between authoritative DB status reads backing up the cache signal. */
    private const int DB_SIGNAL_CHECK_EVERY = 25;

    /** Session-collision re-logins tolerated per cycle before failing loudly. */
    private const int MAX_RELOGIN_ATTEMPTS = 3;

    /**
     * Consecutive respawn waits that produce nothing before we accept the
     * targets are not coming back (bad seed data, a contested spawn, a mob
     * moved). Generous on purpose — waiting is the point.
     */
    protected const int MAX_BARREN_RESPAWN_WAITS = 30;

    /**
     * Hourly rage ticks a participant may wait through before we accept the
     * character simply cannot afford its targets. A day of waiting is plenty:
     * beyond that the run needs different targets, not more patience.
     */
    protected const int MAX_RAGE_WAITS = 24;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public RunParticipant $participant,
        public string $dispatchToken,
    ) {
        $this->onConnection('redis-runs');
        $this->onQueue('runs');
    }

    public function handle(LoginService $loginService): void
    {
        $participant = $this->participant->fresh();

        // Superseded by a later dispatch (pause→resume, restart): this
        // delivery is stale and must not touch the participant.
        if ($participant->dispatch_token !== $this->dispatchToken) {
            return;
        }

        // A stop or pause requested before the worker picked the job up.
        if ($participant->status !== RunStatus::Pending) {
            if ($participant->status === RunStatus::Stopping) {
                $participant->transition(RunStatus::Stopped, 'Stopped before start.');
                $participant->run->refreshStatus();
            }

            if ($participant->status === RunStatus::Pausing) {
                $participant->transition(RunStatus::Paused, 'Paused before start.');
                $participant->run->refreshStatus();
            }

            return;
        }

        $character = $participant->character;

        // One character, one worker — the enrollment guard makes a second
        // driver near-impossible, so a held lock is a loud failure, not a
        // silent retry. TTL outlives the job so a hard-killed worker frees it.
        $lock = Cache::lock("character-run:{$character->id}", $this->timeout + 600);

        if (! $lock->get()) {
            $participant->transition(RunStatus::Failed, 'Character is already driven by another worker.');
            $participant->run->refreshStatus();

            return;
        }

        try {
            $this->drive($participant, $character, $loginService);
        } finally {
            $lock->release();
        }
    }

    /**
     * The engine's own catch block cannot run when the worker is killed
     * outright — a job timeout, an OOM, a hard restart mid-run. Without this
     * the participant, its character, and the run all stay "Running" forever
     * and nothing ever re-drives them, while the character-run lock blocks a
     * restart until its TTL expires two hours later.
     */
    public function failed(?Throwable $exception): void
    {
        $participant = $this->participant->fresh();

        if ($participant === null || $participant->status->isFinished()) {
            return;
        }

        $message = $exception?->getMessage() ?? 'The worker died before the run finished.';

        $participant->transition(RunStatus::Failed, Str::limit($message, 250));

        (new RunEventRecorder($participant))->record(
            RunEventType::Failed,
            $message,
            array_filter(['exception' => $exception === null ? null : $exception::class]),
            RunEvent::LEVEL_ERROR,
        );

        // This job is over either way, so the one-worker-per-character guard has
        // nothing left to protect — free it now so the run can be restarted.
        Cache::lock("character-run:{$participant->character_id}")->forceRelease();

        $participant->loadMissing('run')->run->refreshStatus();
    }

    private function drive(RunParticipant $participant, Character $character, LoginService $loginService): void
    {
        $participant->update(['status' => RunStatus::Running, 'started_at' => now()]);
        $character->update(['status' => CharacterActivity::Running]);

        $run = $participant->run;

        if ($run->status === RunStatus::Pending) {
            $run->update(['status' => RunStatus::Running]);
        }

        $recorder = new RunEventRecorder($participant);
        $log = $recorder->logger();

        try {
            if (! $character->rga->hasSession()) {
                $loginService->login($character->rga);
            }

            $ensurer = BuffEnsurer::forCharacter($character);

            if (! $this->passesCircumspectGate($run, $ensurer, $log, $recorder)) {
                $resumeAt = app(CircumspectGate::class)->resumeAtFor($character);
                $participant->transition(
                    RunStatus::Waiting,
                    "Waiting for Circumspect — resumes {$resumeAt->format('Y-m-d H:i')}.",
                    resumeAt: $resumeAt,
                );

                return;
            }

            $outcome = $this->runEngine(
                $character,
                $participant,
                log: $log,
                signal: $this->signalClosure($participant, $this->circumspectExpiryFor($character, $run), $character),
                ensureBuffs: $this->ensureBuffsClosure($run, $ensurer, $log, $recorder),
                onBattle: function (BattleEvent $event) use ($participant): void {
                    match ($event->outcome) {
                        BattleOutcome::Win => $participant->increment('wins'),
                        BattleOutcome::Loss => $participant->increment('losses'),
                        // The attack happened and cost rage; we just could not
                        // read the result page. That is not an error.
                        BattleOutcome::Unknown => $participant->increment('unknown'),
                        default => $participant->increment('errors'),
                    };
                },
            );

            $participant->transition(
                $outcome->status,
                $outcome->reason,
                $outcome->resumeAt,
                // A clean engine return proves the session works again.
                array_merge($outcome->progress ?? [], ['relogin_attempts' => 0]),
            );

            $this->recordOutcome($recorder, $outcome);
        } catch (SessionCollisionException) {
            $this->recoverSession($participant, $character, $loginService);
        } catch (Throwable $exception) {
            $participant->transition(RunStatus::Failed, $exception->getMessage());
            $recorder->record(
                RunEventType::Failed,
                $exception->getMessage(),
                ['exception' => $exception::class],
                RunEvent::LEVEL_ERROR,
            );

            throw $exception;
        } finally {
            $participant->run->refreshStatus();
        }
    }

    /**
     * File the cycle's end in the durable log: a park (Waiting) and a stop
     * read very differently when someone asks why a run went quiet hours
     * later, and last_activity keeps only whichever came last.
     */
    private function recordOutcome(RunEventRecorder $recorder, ParticipantOutcome $outcome): void
    {
        $type = match ($outcome->status) {
            RunStatus::Waiting => RunEventType::Parked,
            RunStatus::Failed => RunEventType::Failed,
            RunStatus::Stopped, RunStatus::Completed => RunEventType::Stopped,
            default => null,
        };

        if ($type === null) {
            return;
        }

        $recorder->record(
            $type,
            $outcome->reason,
            array_filter([
                'status' => $outcome->status->value,
                'resume_at' => $outcome->resumeAt?->toIso8601String(),
            ]),
            $outcome->status === RunStatus::Failed ? RunEvent::LEVEL_ERROR : RunEvent::LEVEL_INFO,
        );
    }

    /**
     * Session-collision self-heal: one re-login attempt per RGA at a time
     * (the lock stops a 75-character stampede — siblings just wait for the
     * winner's session), then park briefly and let the resume scheduler
     * re-drive the participant. Bounded by a per-cycle attempt budget so a
     * genuinely broken account fails loudly instead of looping forever.
     */
    private function recoverSession(RunParticipant $participant, Character $character, LoginService $loginService): void
    {
        $attempts = (int) ($participant->progress['relogin_attempts'] ?? 0) + 1;

        if ($attempts > self::MAX_RELOGIN_ATTEMPTS) {
            $participant->transition(
                RunStatus::Failed,
                'Session lost repeatedly — giving up after '.self::MAX_RELOGIN_ATTEMPTS.' re-login attempts.',
                progress: ['relogin_attempts' => $attempts],
            );

            return;
        }

        $rga = $character->rga;
        $lock = Cache::lock("rga-relogin:{$rga->id}", 120);

        if ($lock->get()) {
            try {
                $loginService->login($rga->fresh());
            } catch (Throwable $exception) {
                $participant->transition(
                    RunStatus::Failed,
                    Str::limit("Session lost and re-login failed: {$exception->getMessage()}", 250),
                    progress: ['relogin_attempts' => $attempts],
                );

                return;
            } finally {
                $lock->release();
            }
        }

        // Either this worker just restored the session or a sibling is doing
        // it right now — resume shortly and re-check at pickup.
        $participant->transition(
            RunStatus::Waiting,
            'Session dropped — recovered, resuming shortly.',
            resumeAt: now()->addMinute(),
            progress: ['relogin_attempts' => $attempts],
        );
    }

    /**
     * The Circumspect cycle outcome shared by all modes: park the participant
     * until Circumspect's cooldown ends (fresh server reading when reachable),
     * carrying the mode's progress into the next cycle. Rage regenerates
     * during the cooldown, so waking at recharge time restarts a full window.
     *
     * @param  array<string, mixed>|null  $progress
     */
    protected function waitForCircumspect(Character $character, string $reason, ?array $progress = null): ParticipantOutcome
    {
        $resumeAt = app(CircumspectGate::class)->resumeAtFor($character, refresh: true);

        return new ParticipantOutcome(
            RunStatus::Waiting,
            rtrim($reason, '.').". Waiting for Circumspect — resumes {$resumeAt->format('Y-m-d H:i')}.",
            $resumeAt,
            $progress,
        );
    }

    /**
     * The quest cycle outcome shared by quest and quest-list modes: the
     * objective's targets are all dead, so park until they respawn and let the
     * resume scheduler re-drive the participant. Progress is re-read from the
     * game on pickup, so nothing but the barren-wait counter needs carrying.
     *
     * @param  array<string, mixed>  $progress  must already carry the incremented 'respawn_waits'
     */
    protected function waitForRespawn(string $reason, int $waitSeconds, array $progress): ParticipantOutcome
    {
        if ((int) ($progress['respawn_waits'] ?? 0) > self::MAX_BARREN_RESPAWN_WAITS) {
            return new ParticipantOutcome(
                RunStatus::Stopped,
                rtrim($reason, '.').'. Nothing respawned after '.self::MAX_BARREN_RESPAWN_WAITS.' waits — giving up.',
                progress: $progress,
            );
        }

        $resumeAt = now()->addSeconds(max($waitSeconds, 1));

        return new ParticipantOutcome(
            RunStatus::Waiting,
            rtrim($reason, '.').". Resumes {$resumeAt->format('Y-m-d H:i')}.",
            $resumeAt,
            $progress,
        );
    }

    /**
     * The rage cycle: the character cannot pay for its next target and no
     * setting can lower the game's price, so park until the game's hourly rage
     * tick and try again. Shared by every mode — the wait is a property of the
     * game clock, not of what the run is doing.
     *
     * @param  array<string, mixed>  $progress  must already carry the incremented 'rage_waits'
     */
    protected function waitForRage(string $reason, array $progress): ParticipantOutcome
    {
        if ((int) ($progress['rage_waits'] ?? 0) > self::MAX_RAGE_WAITS) {
            return new ParticipantOutcome(
                RunStatus::Stopped,
                rtrim($reason, '.').'. Still short after '.self::MAX_RAGE_WAITS.' rage ticks — giving up.',
                progress: $progress,
            );
        }

        $resumeAt = GameClock::nextRageTickAt();

        return new ParticipantOutcome(
            RunStatus::Waiting,
            rtrim($reason, '.').". Waiting for rage — resumes {$resumeAt->format('Y-m-d H:i')}.",
            $resumeAt,
            $progress,
        );
    }

    /**
     * The rage-wait tally for the next cycle: a cycle that got anything done
     * starts it over, so only a run of fruitless waits ever gives up.
     *
     * @param  array<string, mixed>  $progressIn
     */
    protected function rageWaits(array $progressIn, bool $madeProgress): int
    {
        return $madeProgress ? 1 : (int) ($progressIn['rage_waits'] ?? 0) + 1;
    }

    /**
     * When this character's Circumspect buff runs out, for a run that is gated
     * on it. Read once at pickup — the window is fixed for the whole pass, so
     * the per-iteration check stays a clock comparison instead of a query.
     */
    private function circumspectExpiryFor(Character $character, Run $run): ?CarbonInterface
    {
        if (! $run->require_circumspect) {
            return null;
        }

        return $this->readCircumspectExpiry($character);
    }

    private function readCircumspectExpiry(Character $character): ?CarbonInterface
    {
        return CharacterSkill::with('skill')
            ->where('character_id', $character->id)
            ->where('skill_id', Skill::CIRCUMSPECT_ID)
            ->first()
            ?->buffEndsAt();
    }

    /**
     * The engines' per-iteration control check: the cache signal is the fast
     * path; every Nth call falls back to an authoritative DB read so a lost
     * cache entry can never strand a stop or pause. A gated run also ends its
     * pass the moment Circumspect lapses.
     *
     * @return Closure(): RunSignal
     */
    private function signalClosure(
        RunParticipant $participant,
        ?CarbonInterface $circumspectExpiresAt = null,
        ?Character $character = null,
    ): Closure {
        $calls = 0;

        return function () use ($participant, &$calls, &$circumspectExpiresAt, $character): RunSignal {
            $calls++;

            $signal = $participant->run->currentSignal();

            if ($signal !== RunSignal::None) {
                return $signal;
            }

            if ($circumspectExpiresAt !== null && $circumspectExpiresAt->isPast()) {
                // The snapshot is only the fast path. Just-in-time casting can
                // have renewed Circumspect since pickup, and ending the pass on
                // a stale window would park a run whose buff is actually up.
                $fresh = $character !== null ? $this->readCircumspectExpiry($character) : null;

                if ($fresh !== null && $fresh->isFuture()) {
                    $circumspectExpiresAt = $fresh;

                    return RunSignal::None;
                }

                return RunSignal::CircumspectExpired;
            }

            if ($calls % self::DB_SIGNAL_CHECK_EVERY === 0) {
                return match ($participant->fresh()->status) {
                    RunStatus::Stopping => RunSignal::Stop,
                    RunStatus::Pausing => RunSignal::Pause,
                    default => RunSignal::None,
                };
            }

            return RunSignal::None;
        };
    }

    /**
     * The one thing that still has to happen before the engine starts: a run
     * gated on Circumspect cannot fight without it, so the gate is settled at
     * pickup. Ensuring it brings the rest of the selected set up with it —
     * that is what makes a Circumspect resume restore *all* the buffs, not
     * just the one it was waiting for.
     *
     * Everything else waits for combat; see ensureBuffsClosure().
     *
     * @param  Closure(string): void  $log
     */
    private function passesCircumspectGate(Run $run, BuffEnsurer $ensurer, Closure $log, RunEventRecorder $recorder): bool
    {
        if (! $run->require_circumspect) {
            return true;
        }

        return $ensurer->ensure(includeCircumspect: true, log: $log, events: $recorder)->circumspectActive;
    }

    /**
     * The engines' just-in-time buff hook, invoked immediately before combat
     * rather than at pickup so a buff's duration is spent fighting instead of
     * walking. Idempotent and self-throttling, so an engine may call it before
     * every attack; that is also what re-casts anything that lapses mid-run.
     *
     * @param  Closure(string): void  $log
     * @return Closure(): void
     */
    private function ensureBuffsClosure(Run $run, BuffEnsurer $ensurer, Closure $log, RunEventRecorder $recorder): Closure
    {
        if (! $run->cast_on_start && ! $run->require_circumspect) {
            return function (): void {};
        }

        return function () use ($run, $ensurer, $log, $recorder): void {
            $ensurer->ensure(includeCircumspect: $run->require_circumspect, log: $log, events: $recorder);
        };
    }

    /**
     * Drive the mode's engine and report the participant's outcome.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal  $signal
     * @param  Closure(): void  $ensureBuffs  call immediately before combat
     * @param  Closure(BattleEvent): void  $onBattle
     */
    abstract protected function runEngine(
        Character $character,
        RunParticipant $participant,
        Closure $log,
        Closure $signal,
        Closure $ensureBuffs,
        Closure $onBattle,
    ): ParticipantOutcome;
}
