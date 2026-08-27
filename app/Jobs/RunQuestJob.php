<?php

namespace App\Jobs;

use App\Game\Engine\ParticipantOutcome;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\QuestRunSummary;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunStatus;
use App\Game\Quest\QuestRunner;
use App\Models\Character;
use App\Models\Run;
use App\Models\RunParticipant;
use Closure;

/**
 * One queued job = one character running a single quest to completion.
 */
class RunQuestJob extends RunJob
{
    protected function runEngine(
        Character $character,
        RunParticipant $participant,
        Closure $log,
        Closure $signal,
        Closure $onBattle,
    ): ParticipantOutcome {
        $config = QuestRunConfig::fromArray($participant->run->config);

        $summary = QuestRunner::forCharacter($character, $config)
            ->run(log: $log, signal: $signal, onBattle: $onBattle);

        return $this->outcomeForQuestEnd($summary, $config, $participant->run, $participant->progress ?? [], $character);
    }

    /**
     * Decide what a finished quest cycle means for the participant. Kept as one
     * pure mapping so the park-vs-stop policy (respawn waits, Circumspect,
     * outmatched) lives — and is tested — in a single place.
     *
     * @param  array<string, mixed>  $progressIn
     */
    public function outcomeForQuestEnd(
        QuestRunSummary $summary,
        QuestRunConfig $config,
        Run $run,
        array $progressIn,
        Character $character,
    ): ParticipantOutcome {
        // Circumspect lapsed mid-quest, or rage ran out on a gated run: park
        // until it can be recast, then pick the quest back up from the game's
        // own record of progress.
        if ($summary->endReason === RunEndReason::CircumspectExpired
            || ($summary->endReason === RunEndReason::RageExhausted && $run->require_circumspect)
        ) {
            return $this->waitForCircumspect($character, $summary->stopReason, ['respawn_waits' => 0]);
        }

        // Targets are dead but not gone: park until they respawn and continue
        // the same objective. A cycle that made progress starts the barren
        // counter over — only a run of fruitless waits gives up.
        if ($summary->endReason === RunEndReason::TargetsDepleted) {
            $madeProgress = $summary->kills > 0 || $summary->stepsCompleted > 0;
            $waits = $madeProgress ? 1 : (int) ($progressIn['respawn_waits'] ?? 0) + 1;

            return $this->waitForRespawn($summary->stopReason, $config->respawnWaitSeconds, [
                'respawn_waits' => $waits,
            ]);
        }

        $status = match ($summary->endReason) {
            RunEndReason::ExternalStop => RunStatus::Stopped,
            RunEndReason::ExternalPause => RunStatus::Paused,
            RunEndReason::Completed => RunStatus::Completed,
            // Terminal by design: parking would only burn the same rage on the
            // same unwinnable fight later.
            RunEndReason::Outmatched => RunStatus::Stopped,
            default => RunStatus::Stopped,
        };

        return new ParticipantOutcome($status, $summary->stopReason, progress: ['respawn_waits' => 0]);
    }
}
