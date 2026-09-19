<?php

namespace App\Jobs;

use App\Game\Auth\LoginService;
use App\Game\Data\RageReserveWindow;
use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\RageReserveGate;
use App\Game\Engine\RunEventRecorder;
use App\Game\Engine\TransientFailure;
use App\Game\Engine\WorkerDeathRecovery;
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
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RedisException;
use Throwable;

/**
 * Base for one-character run jobs. Owns the participant lifecycle (pre-pickup
 * stop/pause, running → parked/finished transitions, tally callbacks, failure
 * handling) and the long-lived queue placement. Subclasses only drive their
 * engine. Lives for the whole run (possibly hours) on the redis-runs
 * connection whose retry_after exceeds the supervisor timeout, so a live run
 * is never re-dispatched.
 *
 * Interruptible so a worker told to quit (deploy, horizon:terminate) does not
 * have to sit out the rest of the run: the engine ends its pass at the next
 * signal check and the participant parks for the resume scheduler.
 *
 * The dispatch token is this job's lease on the participant. Every write the
 * job makes is conditional on still holding it, because a worker can be
 * presumed dead (see WorkerDeathRecovery) while it is merely slow, and a job
 * orphaned by a killed worker is redelivered hours later — neither may touch
 * a participant that has since been handed to another job.
 */
abstract class RunJob implements Interruptible, ShouldQueue
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

    /**
     * How long a run interrupted by a worker shutdown stays parked. Just long
     * enough for the replacement workers to be up before the resume scheduler
     * re-dispatches it.
     */
    private const int WORKER_RESTART_RESUME_SECONDS = 60;

    /** Network/Redis/database blips in a row tolerated before failing loudly. */
    private const int MAX_TRANSIENT_FAILURES = 5;

    /** How long a run parks after such a blip before trying again. */
    private const int TRANSIENT_RETRY_SECONDS = 120;

    /**
     * The pass ends itself this long before the job timeout and parks, so a
     * run that legitimately outlives one job is handed to the next instead of
     * being killed mid-request by the worker's alarm.
     */
    private const int PASS_END_MARGIN_SECONDS = 600;

    public int $timeout = 7200;

    /**
     * Not a retry budget for the run — the engine never runs twice, because a
     * second delivery no longer finds the participant Pending under its token
     * and no-ops. More than one try only means a job redelivered after its
     * worker was killed is acknowledged quietly rather than failed as
     * "attempted too many times".
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public bool $failOnTimeout = true;

    /** Set from the worker's signal handler; read by the engines' signal closure. */
    private bool $workerShuttingDown = false;

    /** Another dispatch owns the participant now; this job must not write to it again. */
    private bool $leaseLost = false;

    /** Set when a rage-reserve window opened mid-pass and is why the engine was told to end it. */
    private ?RageReserveWindow $openedReserve = null;

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
     * Called by the queue worker, from inside its async signal handler, when it
     * receives SIGTERM/SIGQUIT/SIGINT while this job is running. Only a flag is
     * flipped here — a signal handler can fire in the middle of any statement,
     * so all the real work happens at the engine's next signal check.
     */
    public function interrupted(int $signal): void
    {
        $this->workerShuttingDown = true;
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

        // A stale delivery: the participant has been re-dispatched since, and
        // failing it here would kill a healthy run (and free its lock) over a
        // job that stopped mattering hours ago.
        if ($participant->dispatch_token !== $this->dispatchToken) {
            return;
        }

        // The queue's own verdicts on a worker that went away mid-run — a
        // redelivery past its tries, or a timeout (TimeoutExceededException is
        // a MaxAttemptsExceededException). Nothing is wrong with the run
        // itself, so it is re-driven, not failed.
        if ($exception instanceof MaxAttemptsExceededException) {
            app(WorkerDeathRecovery::class)->recover($participant, 'The worker driving this run died.');

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
        $participant->update(['status' => RunStatus::Running, 'started_at' => now(), 'heartbeat_at' => now()]);
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

            // Saving rage for an event the user picked: park before spending any.
            $reserve = app(RageReserveGate::class)->nextWindowFor($run, $character);

            if ($reserve !== null && $reserve->isOpen()) {
                $this->parkForRageReserve($participant, $recorder, $reserve);

                return;
            }

            $outcome = $this->runEngine(
                $character,
                $participant,
                log: $log,
                signal: $this->signalClosure($participant, $this->circumspectExpiryFor($character, $run), $character, $reserve),
                ensureBuffs: $this->ensureBuffsClosure($run, $ensurer, $log, $recorder),
                onBattle: function (BattleEvent $event) use ($participant): void {
                    // Every engine reports each fight here, which makes this
                    // the one place a battle learns which run it belongs to.
                    $event->update(['run_id' => $participant->run_id]);

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

            if (! $this->holdsLease($participant)) {
                return;
            }

            // The pass was ended for the reserve window, which the engine only
            // knows as "end now and keep your progress" — say the real reason
            // and resume when the event is over, not in a minute.
            if ($this->openedReserve !== null && $outcome->status === RunStatus::Waiting) {
                $outcome = new ParticipantOutcome(
                    RunStatus::Waiting,
                    $this->openedReserve->reason(),
                    $this->openedReserve->resumeAt,
                    $outcome->progress,
                );
            }

            $participant->transition(
                $outcome->status,
                $outcome->reason,
                $outcome->resumeAt,
                // A clean engine return proves the session, the network and
                // the worker all held up, so every recovery budget starts over.
                array_merge($outcome->progress ?? [], [
                    'relogin_attempts' => 0,
                    'transient_failures' => 0,
                    'worker_deaths' => 0,
                ]),
            );

            $this->recordOutcome($recorder, $outcome);
        } catch (SessionCollisionException) {
            if ($this->holdsLease($participant)) {
                $this->recoverSession($participant, $character, $loginService);
            }
        } catch (Throwable $exception) {
            if (! $this->holdsLease($participant)) {
                return;
            }

            if (app(TransientFailure::class)->matches($exception)) {
                $this->recoverFromTransient($participant, $recorder, $exception);

                return;
            }

            $this->failParticipant($participant, $recorder, $exception);

            // Terminal, and said so explicitly: the job is marked failed for
            // the dashboard without leaning on the retry budget to stop it.
            report($exception);
            $this->fail($exception);
        } finally {
            if (! $this->leaseLost) {
                $participant->run->refreshStatus();
            }
        }
    }

    /**
     * Whether this job still owns the participant. Checked before every write
     * that ends the pass; the signal closure keeps the flag current in between.
     */
    private function holdsLease(RunParticipant $participant): bool
    {
        if (! $this->leaseLost) {
            $this->leaseLost = RunParticipant::whereKey($participant->id)->value('dispatch_token') !== $this->dispatchToken;
        }

        return ! $this->leaseLost;
    }

    private function parkForRageReserve(RunParticipant $participant, RunEventRecorder $recorder, RageReserveWindow $reserve): void
    {
        $participant->transition(RunStatus::Waiting, $reserve->reason(), $reserve->resumeAt);
        $recorder->record(RunEventType::Parked, $reserve->reason(), [
            'status' => RunStatus::Waiting->value,
            'resume_at' => $reserve->resumeAt->toIso8601String(),
            'reserve_for' => $reserve->event->value,
        ]);
    }

    private function failParticipant(RunParticipant $participant, RunEventRecorder $recorder, Throwable $exception): void
    {
        $participant->transition(RunStatus::Failed, $exception->getMessage());
        $recorder->record(
            RunEventType::Failed,
            $exception->getMessage(),
            ['exception' => $exception::class],
            RunEvent::LEVEL_ERROR,
        );
    }

    /**
     * A blip that says nothing about the run (see TransientFailure): park
     * briefly and let the resume scheduler try again. Bounded, like every
     * park, so an outage that never ends still fails loudly.
     */
    private function recoverFromTransient(RunParticipant $participant, RunEventRecorder $recorder, Throwable $exception): void
    {
        $failures = (int) ($participant->progress['transient_failures'] ?? 0) + 1;

        if ($failures > self::MAX_TRANSIENT_FAILURES) {
            $this->failParticipant($participant, $recorder, $exception);

            return;
        }

        $resumeAt = now()->addSeconds(self::TRANSIENT_RETRY_SECONDS);
        $reason = Str::limit("Connection trouble — retrying at {$resumeAt->format('H:i')}: {$exception->getMessage()}", 250);

        $participant->transition(RunStatus::Waiting, $reason, $resumeAt, ['transient_failures' => $failures]);
        $recorder->record(
            RunEventType::Parked,
            $reason,
            [
                'status' => RunStatus::Waiting->value,
                'resume_at' => $resumeAt->toIso8601String(),
                'exception' => $exception::class,
                'attempt' => $failures,
            ],
            RunEvent::LEVEL_WARNING,
        );
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
     * The worker-shutdown outcome shared by all modes: nothing is wrong with
     * the run, the process driving it is just going away. Park briefly and let
     * the resume scheduler hand it to a fresh worker. Deliberately leaves the
     * barren-wait and rage-wait counters alone — this wait says nothing about
     * the targets.
     *
     * @param  array<string, mixed>|null  $progress
     */
    protected function waitForWorkerRestart(?array $progress = null): ParticipantOutcome
    {
        return new ParticipantOutcome(
            RunStatus::Waiting,
            'Worker restarting — resuming shortly.',
            now()->addSeconds(self::WORKER_RESTART_RESUME_SECONDS),
            $progress,
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
        ?RageReserveWindow $reserve = null,
    ): Closure {
        $calls = 0;
        $lastHeartbeat = now();
        $passEndsAt = now()->addSeconds($this->timeout - self::PASS_END_MARGIN_SECONDS);

        return function () use ($participant, &$calls, &$lastHeartbeat, $passEndsAt, &$circumspectExpiresAt, $character, $reserve): RunSignal {
            $calls++;

            // Lost the participant to another dispatch: end the pass like a
            // worker shutdown, and drive() then leaves the participant alone.
            if ($this->leaseLost || ! $this->beat($participant, $lastHeartbeat)) {
                return RunSignal::WorkerShutdown;
            }

            $signal = $this->cachedSignal($participant);

            if ($signal !== RunSignal::None) {
                return $signal;
            }

            if ($passEndsAt->isPast()) {
                return RunSignal::WorkerShutdown;
            }

            // The reserve window was still ahead at pickup and has opened
            // since: end the pass the same way, and drive() parks it properly.
            if ($reserve !== null && $reserve->isOpen()) {
                $this->openedReserve = $reserve;

                return RunSignal::WorkerShutdown;
            }

            // The worker is quitting, so the pass ends here whatever happens.
            // Ask the database first: parking over a stop or pause whose cache
            // signal was lost would silently discard the user's request.
            if ($this->workerShuttingDown) {
                $requested = $this->signalFromStatus($participant);

                return $requested === RunSignal::None ? RunSignal::WorkerShutdown : $requested;
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
                return $this->signalFromStatus($participant);
            }

            return RunSignal::None;
        };
    }

    /**
     * Prove this job is alive, at most once per heartbeat interval. The stamp
     * is conditional on the dispatch token, so the same query is also how a
     * job learns it has been superseded.
     *
     * @return bool false when the participant now belongs to another dispatch
     */
    private function beat(RunParticipant $participant, CarbonInterface &$lastHeartbeat): bool
    {
        if ($lastHeartbeat->diffInSeconds(now()) < (int) config('outwar.runs.heartbeat_seconds')) {
            return true;
        }

        $lastHeartbeat = now();

        $stamped = RunParticipant::whereKey($participant->id)
            ->where('dispatch_token', $this->dispatchToken)
            ->toBase()
            ->update(['heartbeat_at' => $lastHeartbeat]);

        $this->leaseLost = $stamped === 0;

        return ! $this->leaseLost;
    }

    /**
     * The fast path of the control check. Redis being briefly unreachable must
     * not end a run that only asked it whether to stop, so a failed read falls
     * back to the row the signal mirrors.
     */
    private function cachedSignal(RunParticipant $participant): RunSignal
    {
        try {
            return $participant->run->currentSignal();
        } catch (RedisException) {
            return $this->signalFromStatus($participant);
        }
    }

    /** The authoritative read behind the cache signal: what the participant's own row asks for. */
    private function signalFromStatus(RunParticipant $participant): RunSignal
    {
        return match ($participant->fresh()->status) {
            RunStatus::Stopping => RunSignal::Stop,
            RunStatus::Pausing => RunSignal::Pause,
            default => RunSignal::None,
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
