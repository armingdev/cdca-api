<?php

namespace App\Jobs;

use App\Game\Auth\LoginService;
use App\Game\Engine\ParticipantOutcome;
use App\Game\Enums\BattleOutcome;
use App\Game\Enums\CharacterActivity;
use App\Game\Enums\RunSignal;
use App\Game\Enums\RunStatus;
use App\Game\Exceptions\SessionCollisionException;
use App\Game\Skills\CircumspectGate;
use App\Game\Skills\SkillCaster;
use App\Game\Skills\SkillSyncService;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Run;
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

        $participant->transition(
            RunStatus::Failed,
            Str::limit($exception?->getMessage() ?? 'The worker died before the run finished.', 250),
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

        $log = function (string $message) use ($participant): void {
            $participant->update(['last_activity' => Str::limit($message, 250)]);
        };

        try {
            if (! $character->rga->hasSession()) {
                $loginService->login($character->rga);
            }

            if (! $this->applySkillOptions($character, $participant, $log)) {
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
                signal: $this->signalClosure($participant, $this->circumspectExpiryFor($character, $run)),
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
        } catch (SessionCollisionException) {
            $this->recoverSession($participant, $character, $loginService);
        } catch (Throwable $exception) {
            $participant->transition(RunStatus::Failed, $exception->getMessage());

            throw $exception;
        } finally {
            $participant->run->refreshStatus();
        }
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
     * When this character's Circumspect buff runs out, for a run that is gated
     * on it. Read once at pickup — the window is fixed for the whole pass, so
     * the per-iteration check stays a clock comparison instead of a query.
     */
    private function circumspectExpiryFor(Character $character, Run $run): ?CarbonInterface
    {
        if (! $run->require_circumspect) {
            return null;
        }

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
    private function signalClosure(RunParticipant $participant, ?CarbonInterface $circumspectExpiresAt = null): Closure
    {
        $calls = 0;

        return function () use ($participant, &$calls, $circumspectExpiresAt): RunSignal {
            $calls++;

            $signal = $participant->run->currentSignal();

            if ($signal !== RunSignal::None) {
                return $signal;
            }

            if ($circumspectExpiresAt !== null && $circumspectExpiresAt->isPast()) {
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
     * Cast-on-start and Circumspect gating — cross-cutting, run-level, applied
     * before any mode engine. Returns false only when the run requires
     * Circumspect and it could not be made active (the run is gated off).
     *
     * @param  Closure(string): void  $log
     */
    private function applySkillOptions(Character $character, RunParticipant $participant, Closure $log): bool
    {
        $run = $participant->run;

        if (! $run->cast_on_start && ! $run->require_circumspect) {
            return true;
        }

        $this->preSyncSkills($character, $run->cast_on_start, $run->require_circumspect, $log);

        $caster = SkillCaster::forCharacter($character);

        if ($run->cast_on_start) {
            $caster->castOnStart($log);
        }

        if ($run->require_circumspect) {
            return $caster->ensureCircumspect($log);
        }

        return true;
    }

    /**
     * Full pre-sync before casting: refresh trained levels, skill points, and
     * active buffs from the game (5 requests), then read the authoritative
     * recharge for each selected skill that is not already buff-active (one
     * request each), so cast decisions never rely on stale local cooldowns.
     */
    private function preSyncSkills(Character $character, bool $castOnStart, bool $requireCircumspect, Closure $log): void
    {
        $sync = SkillSyncService::forCharacter($character);

        $log('Syncing skills with the game…');
        $sync->sync();

        $states = $character->skills()
            ->with('skill')
            ->where(function ($query) use ($castOnStart, $requireCircumspect) {
                $query->when($castOnStart, fn ($q) => $q->orWhere('cast_on_start', true))
                    ->when($requireCircumspect, fn ($q) => $q->orWhere('skill_id', Skill::CIRCUMSPECT_ID));
            })
            ->get();

        $refreshed = 0;

        foreach ($states as $state) {
            if ($state->isBuffActive() || ! $state->isCastable()) {
                continue;
            }

            $sync->refreshSkillInfo($state->skill);
            $refreshed++;
        }

        $log("Skills synced ({$refreshed} recharge check(s)).");
    }

    /**
     * Drive the mode's engine and report the participant's outcome.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): RunSignal  $signal
     * @param  Closure(BattleEvent): void  $onBattle
     */
    abstract protected function runEngine(
        Character $character,
        RunParticipant $participant,
        Closure $log,
        Closure $signal,
        Closure $onBattle,
    ): ParticipantOutcome;
}
