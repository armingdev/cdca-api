<?php

namespace App\Jobs;

use App\Game\Auth\LoginService;
use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Enums\BattleOutcome;
use App\Game\Enums\RunStatus;
use App\Models\BattleEvent;
use App\Models\RunParticipant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * One queued job = one character's mob run. Lives for the whole attack loop
 * (possibly hours), so it runs on the dedicated redis-runs connection whose
 * retry_after exceeds the supervisor timeout. External stop = the
 * participant row flipping to Stopping, polled every loop iteration.
 */
class RunMobJob implements ShouldQueue
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

            $config = MobRunConfig::fromArray($participant->run->config);

            $summary = MobRunner::forCharacter($character, $config)->run(
                log: fn (string $message) => $participant->update(['last_activity' => Str::limit($message, 250)]),
                shouldStop: fn (): bool => $participant->stopRequested(),
                onBattle: function (BattleEvent $event) use ($participant) {
                    match ($event->outcome) {
                        BattleOutcome::Win => $participant->increment('wins'),
                        BattleOutcome::Loss => $participant->increment('losses'),
                        default => $participant->increment('errors'),
                    };
                },
            );

            $participant->update([
                'status' => $summary->externallyStopped ? RunStatus::Stopped : RunStatus::Completed,
                'last_activity' => $summary->stopReason,
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
}
