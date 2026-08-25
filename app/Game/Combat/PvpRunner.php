<?php

namespace App\Game\Combat;

use App\Game\Combat\Targets\PvpTargetSource;
use App\Game\Data\AttackTarget;
use App\Game\Engine\PvpRunConfig;
use App\Game\Engine\PvpRunSummary;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\BattleOutcome;
use App\Game\Enums\RunSignal;
use App\Models\BattleEvent;
use App\Models\Character;
use Closure;

/**
 * PvP mode: pull a target list from the configured source, drop everything on
 * cooldown or outside our level band, and attack the rest.
 *
 * The cooldown filter is what makes a recurring run economical — without it
 * every pass after the first spends its requests collecting refusals.
 */
class PvpRunner
{
    private int $attacks = 0;

    private int $wins = 0;

    private int $losses = 0;

    private int $unknown = 0;

    private int $skippedOnCooldown = 0;

    public function __construct(
        private readonly Character $character,
        private readonly PvpRunConfig $config,
        private readonly PvpTargetSource $source,
        private readonly PvpAttackService $attacker,
        private readonly AttackCooldownTracker $cooldowns,
        private readonly StatsService $stats,
    ) {}

    public static function forCharacter(Character $character, PvpRunConfig $config, PvpTargetSource $source): self
    {
        return new self(
            $character,
            $config,
            $source,
            PvpAttackService::forCharacter($character),
            AttackCooldownTracker::forCharacter($character, $config->cooldownMinutes),
            StatsService::forCharacter($character),
        );
    }

    /**
     * @param  Closure(string): void|null  $log
     * @param  Closure(): RunSignal|null  $signal
     * @param  Closure(BattleEvent): void|null  $onBattle
     */
    public function run(?Closure $log = null, ?Closure $signal = null, ?Closure $onBattle = null): PvpRunSummary
    {
        $log ??= fn (string $message) => null;

        // The game's own log is the only cooldown record that survives a
        // restart, a second client, or an attack made by hand in the browser.
        $blocking = $this->cooldowns->syncFromAttackLog();

        if ($blocking > 0) {
            $log("{$blocking} target(s) still on cooldown from the attack log.");
        }

        $all = $this->source->targets();

        if ($all === []) {
            return $this->summary(
                completed: false,
                reason: "No targets from the {$this->source->label()}.",
                endReason: RunEndReason::Stuck,
            );
        }

        $targets = $this->cooldowns->attackable($all);
        $this->skippedOnCooldown = count($all) - count($targets);

        $log(sprintf(
            '%d target(s) from the %s, %d attackable now.',
            count($all),
            $this->source->label(),
            count($targets),
        ));

        // Every target blocked is the ordinary state of a recurring run, not
        // a failure — it must finish Completed so the restart scheduler keeps
        // the run armed for the next pass.
        if ($targets === []) {
            return $this->summary(
                completed: true,
                reason: $this->nothingToDoReason(),
                endReason: RunEndReason::Completed,
            );
        }

        $current = $this->stats->refresh();

        foreach ($targets as $target) {
            for ($i = 0; $i < $this->config->attacksPerTarget; $i++) {
                $control = $signal !== null ? $signal() : RunSignal::None;

                if ($control === RunSignal::Stop) {
                    return $this->summary(false, 'Stop requested.', RunEndReason::ExternalStop);
                }

                if ($control === RunSignal::Pause) {
                    return $this->summary(false, 'Pause requested.', RunEndReason::ExternalPause);
                }

                if ($current->rage < $this->config->stopRage) {
                    return $this->summary(
                        completed: false,
                        reason: "Rage below the {$this->config->stopRage} floor.",
                        endReason: RunEndReason::RageExhausted,
                    );
                }

                if ($this->config->maxAttacks !== null && $this->attacks >= $this->config->maxAttacks) {
                    return $this->summary(
                        completed: true,
                        reason: "Reached the {$this->config->maxAttacks}-attack cap.",
                        endReason: RunEndReason::TargetReached,
                    );
                }

                $ready = $this->readyTarget($target, $log);

                if ($ready === null) {
                    break;
                }

                $event = $this->attacker->attack($ready, $this->config->message);
                $this->attacks++;
                $this->tally($event->outcome);
                $onBattle?->__invoke($event);
                $log($this->line($ready->name, $event->outcome));

                if ($event->outcome === BattleOutcome::Failed) {
                    $this->handleRefusal($ready);

                    break;
                }

                $this->cooldowns->recordAttack($ready);
                $current = $this->stats->refresh();
            }
        }

        return $this->summary(true, 'PvP run complete.', RunEndReason::Completed);
    }

    /**
     * Targets from crew rosters and brawl standings arrive without a hash;
     * mint one before attacking. Hitlist and search targets already have one,
     * so this costs nothing for them.
     */
    private function readyTarget(AttackTarget $target, Closure $log): ?AttackTarget
    {
        if ($target->isReadyToAttack()) {
            return $target;
        }

        $resolved = $this->attacker->refreshHash($target);

        if ($resolved === null) {
            $log("Could not resolve an attack hash for {$target->name} — skipping.");
        }

        return $resolved;
    }

    /**
     * A refusal that names its elapsed minutes tells us exactly when the
     * target frees up, so record that rather than guessing a full window.
     */
    private function handleRefusal(AttackTarget $target): void
    {
        $refusal = $this->attacker->lastRefusal();

        if ($refusal !== null && $this->cooldowns->recordRefusal($target, $refusal) !== null) {
            $this->skippedOnCooldown++;
        }
    }

    private function nothingToDoReason(): string
    {
        $wait = $this->cooldowns->nextFreeInMinutes();

        if ($wait === null) {
            return 'No attackable targets — all are outside the level band.';
        }

        return "All {$this->skippedOnCooldown} target(s) on cooldown; next free in {$wait}m.";
    }

    private function tally(BattleOutcome $outcome): void
    {
        match ($outcome) {
            BattleOutcome::Win => $this->wins++,
            BattleOutcome::Loss => $this->losses++,
            BattleOutcome::Unknown => $this->unknown++,
            default => null,
        };
    }

    private function line(string $opponent, BattleOutcome $outcome): string
    {
        return match ($outcome) {
            BattleOutcome::Win => "Beat {$opponent}.",
            BattleOutcome::Loss => "Lost to {$opponent}.",
            // The attack landed; the result page did not match a known
            // win/loss shape. Saying "failed" would misreport it.
            BattleOutcome::Unknown => "Attacked {$opponent} — result unreadable.",
            default => "Attack on {$opponent} failed.",
        };
    }

    private function summary(bool $completed, string $reason, RunEndReason $endReason): PvpRunSummary
    {
        return new PvpRunSummary(
            completed: $completed,
            attacks: $this->attacks,
            stopReason: $reason,
            endReason: $endReason,
            wins: $this->wins,
            losses: $this->losses,
            unknown: $this->unknown,
            skippedOnCooldown: $this->skippedOnCooldown,
            nextFreeInMinutes: $this->cooldowns->nextFreeInMinutes(),
        );
    }
}
