<?php

namespace App\Jobs;

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Engine\MobRunSummary;
use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\Run;
use App\Models\RunParticipant;
use Closure;

/**
 * One queued job = one character's mob run, possibly cycling over multiple
 * passes (run_count / attack_interval_seconds / Circumspect windows) with
 * progress carried between cycles.
 */
class RunMobJob extends RunJob
{
    /** Minimum wait between passes — mobs need time to respawn. */
    private const int MIN_PASS_INTERVAL_SECONDS = 60;

    /** Rage-regeneration wait when a pass rage-outs without a Circumspect clock to key off. */
    private const int RAGE_REGEN_WAIT_SECONDS = 1800;

    protected function runEngine(
        Character $character,
        RunParticipant $participant,
        Closure $log,
        Closure $signal,
        Closure $onBattle,
    ): ParticipantOutcome {
        $config = MobRunConfig::fromArray($participant->run->config);
        $progress = $participant->progress ?? [];

        $summary = MobRunner::forCharacter($character, $config)->run(
            log: $log,
            signal: $signal,
            onBattle: $onBattle,
            killsAlreadyDone: (int) ($progress['kills_done'] ?? 0),
        );

        if ($summary->endReason === RunEndReason::ExternalStop || $summary->endReason === RunEndReason::ExternalPause) {
            return new ParticipantOutcome(
                $summary->endReason === RunEndReason::ExternalStop ? RunStatus::Stopped : RunStatus::Paused,
                $summary->stopReason,
                progress: ['kills_done' => (int) ($progress['kills_done'] ?? 0) + $summary->wins],
            );
        }

        return $this->outcomeForPassEnd($summary, $config, $participant->run, $progress, $character);
    }

    /**
     * Decide what a finished pass means for the participant. Kept as one pure
     * mapping so the run_count × interval × require_circumspect matrix lives
     * (and is tested) in a single place.
     *
     * @param  array<string, mixed>  $progressIn
     */
    public function outcomeForPassEnd(
        MobRunSummary $summary,
        MobRunConfig $config,
        Run $run,
        array $progressIn,
        Character $character,
    ): ParticipantOutcome {
        $cyclesDone = (int) ($progressIn['cycles_done'] ?? 0) + 1;
        $progress = [
            'kills_done' => (int) ($progressIn['kills_done'] ?? 0) + $summary->wins,
            'cycles_done' => $cyclesDone,
        ];

        // Hard caps end the run outright, whatever the cycling options say.
        if ($summary->endReason === RunEndReason::TargetReached) {
            return new ParticipantOutcome(RunStatus::Completed, $summary->stopReason, progress: $progress);
        }

        // Waiting cannot fix being too weak — no exp or gear arrives while
        // parked — so an outmatched pass ends the run rather than cycling.
        if ($summary->endReason === RunEndReason::Outmatched) {
            return new ParticipantOutcome(RunStatus::Stopped, $summary->stopReason, progress: $progress);
        }

        if ($config->runCount > 0 && $cyclesDone >= $config->runCount) {
            return new ParticipantOutcome(
                RunStatus::Completed,
                "Reached {$config->runCount} pass(es).",
                progress: $progress,
            );
        }

        $cycling = $config->runCount > 0
            || $config->attackIntervalSeconds !== null
            || $run->require_circumspect;

        if (! $cycling) {
            return new ParticipantOutcome(RunStatus::Completed, $summary->stopReason, progress: $progress);
        }

        if ($summary->endReason === RunEndReason::RageExhausted) {
            if ($run->require_circumspect) {
                return $this->waitForCircumspect($character, $summary->stopReason, $progress);
            }

            $waitSeconds = max($config->attackIntervalSeconds ?? 0, self::RAGE_REGEN_WAIT_SECONDS);
            $resumeAt = now()->addSeconds($waitSeconds);

            return new ParticipantOutcome(
                RunStatus::Waiting,
                "Rage depleted — pass {$cyclesDone} done, next at {$resumeAt->format('Y-m-d H:i')}.",
                $resumeAt,
                $progress,
            );
        }

        $waitSeconds = max($config->attackIntervalSeconds ?? self::MIN_PASS_INTERVAL_SECONDS, self::MIN_PASS_INTERVAL_SECONDS);
        $resumeAt = now()->addSeconds($waitSeconds);

        return new ParticipantOutcome(
            RunStatus::Waiting,
            "Pass {$cyclesDone} complete — next at {$resumeAt->format('Y-m-d H:i')}.",
            $resumeAt,
            $progress,
        );
    }
}
