<?php

namespace App\Jobs;

use App\Game\Auth\LoginService;
use App\Game\Enums\BattleOutcome;
use App\Game\Enums\RunStatus;
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
 * stop, running → finished transitions, tally callbacks, failure handling)
 * and the long-lived queue placement. Subclasses only drive their engine.
 * Lives for the whole run (possibly hours) on the redis-runs connection whose
 * retry_after exceeds the supervisor timeout, so a live run is never
 * re-dispatched.
 */
abstract class RunJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public RunParticipant $participant)
    {
        $this->onConnection('redis-runs');
        $this->onQueue('runs');
    }

    public function handle(LoginService $loginService): void
    {
        $participant = $this->participant->fresh();

        // A stop requested before the worker picked the job up.
        if ($participant->status !== RunStatus::Pending) {
            if ($participant->status === RunStatus::Stopping) {
                $participant->update(['status' => RunStatus::Stopped, 'finished_at' => now()]);
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
                $participant->update([
                    'status' => RunStatus::Stopped,
                    'last_activity' => 'Circumspect not active — run gated.',
                    'finished_at' => now(),
                ]);

                return;
            }

            [$status, $reason] = $this->runEngine(
                $character,
                $participant,
                log: $log,
                shouldStop: fn (): bool => $participant->stopRequested(),
                onBattle: function (BattleEvent $event) use ($participant): void {
                    match ($event->outcome) {
                        BattleOutcome::Win => $participant->increment('wins'),
                        BattleOutcome::Loss => $participant->increment('losses'),
                        default => $participant->increment('errors'),
                    };
                },
            );

            $participant->update([
                'status' => $status,
                'last_activity' => $reason,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $participant->update([
                'status' => RunStatus::Failed,
                'last_activity' => Str::limit($exception->getMessage(), 250),
                'finished_at' => now(),
            ]);

            throw $exception;
        } finally {
            $participant->run->refreshStatus();
        }
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
     * Drive the mode's engine to completion.
     *
     * @param  Closure(string): void  $log
     * @param  Closure(): bool  $shouldStop
     * @param  Closure(BattleEvent): void  $onBattle
     * @return array{0: RunStatus, 1: string} final status + reason line
     */
    abstract protected function runEngine(
        Character $character,
        RunParticipant $participant,
        Closure $log,
        Closure $shouldStop,
        Closure $onBattle,
    ): array;
}
