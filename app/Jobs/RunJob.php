<?php

namespace App\Jobs;

use App\Game\Auth\LoginService;
use App\Game\Engine\ParticipantOutcome;
use App\Game\Enums\BattleOutcome;
use App\Game\Enums\RunSignal;
use App\Game\Enums\RunStatus;
use App\Game\Skills\CircumspectGate;
use App\Game\Skills\SkillCaster;
use App\Game\Skills\SkillSyncService;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\RunParticipant;
use App\Models\Skill;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
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

        $participant->update(['status' => RunStatus::Running, 'started_at' => now()]);
        $character = $participant->character;
        $log = fn (string $message) => $participant->update(['last_activity' => Str::limit($message, 250)]);

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
                signal: $this->signalClosure($participant),
                onBattle: function (BattleEvent $event) use ($participant): void {
                    match ($event->outcome) {
                        BattleOutcome::Win => $participant->increment('wins'),
                        BattleOutcome::Loss => $participant->increment('losses'),
                        default => $participant->increment('errors'),
                    };
                },
            );

            $participant->transition(
                $outcome->status,
                $outcome->reason,
                $outcome->resumeAt,
                $outcome->progress,
            );
        } catch (Throwable $exception) {
            $participant->transition(RunStatus::Failed, $exception->getMessage());

            throw $exception;
        } finally {
            $participant->run->refreshStatus();
        }
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
     * The engines' per-iteration control check: the cache signal is the fast
     * path; every Nth call falls back to an authoritative DB read so a lost
     * cache entry can never strand a stop or pause.
     *
     * @return Closure(): RunSignal
     */
    private function signalClosure(RunParticipant $participant): Closure
    {
        $calls = 0;

        return function () use ($participant, &$calls): RunSignal {
            $calls++;

            $signal = $participant->run->currentSignal();

            if ($signal !== RunSignal::None) {
                return $signal;
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
