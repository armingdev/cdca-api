<?php

namespace App\Jobs;

use App\Game\Auth\LoginService;
use App\Game\Enums\BattleOutcome;
use App\Game\Enums\RunStatus;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\RunParticipant;
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

        try {
            if (! $character->rga->hasSession()) {
                $loginService->login($character->rga);
            }

            [$status, $reason] = $this->runEngine(
                $character,
                $participant,
                log: fn (string $message) => $participant->update(['last_activity' => Str::limit($message, 250)]),
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
