<?php

namespace App\Game\Combat;

use App\Game\Engine\PvpRunConfig;
use App\Game\Engine\PvpRunSummary;
use App\Game\Enums\BattleOutcome;
use App\Models\BattleEvent;
use App\Models\Character;
use Closure;

/**
 * PvP mode: walk a target list, searching each name for a fresh attack hash
 * and attacking it up to N times, until the list is exhausted or the rage
 * floor is hit. Each attack re-searches so the per-render hash is always
 * fresh (the game rotates it).
 */
class PvpRunner
{
    private int $attacks = 0;

    public function __construct(
        private readonly Character $character,
        private readonly PvpRunConfig $config,
        private readonly PvpAttackService $attacker,
        private readonly StatsService $stats,
    ) {}

    public static function forCharacter(Character $character, PvpRunConfig $config): self
    {
        return new self(
            $character,
            $config,
            PvpAttackService::forCharacter($character),
            StatsService::forCharacter($character),
        );
    }

    /**
     * @param  Closure(string): void|null  $log
     * @param  Closure(): bool|null  $shouldStop
     * @param  Closure(BattleEvent): void|null  $onBattle
     */
    public function run(?Closure $log = null, ?Closure $shouldStop = null, ?Closure $onBattle = null): PvpRunSummary
    {
        $log ??= fn (string $message) => null;

        if ($this->config->targets === []) {
            return $this->summary(completed: false, reason: 'No PvP targets configured.');
        }

        $current = $this->stats->refresh();

        foreach ($this->config->targets as $name) {
            for ($i = 0; $i < $this->config->attacksPerTarget; $i++) {
                if ($shouldStop !== null && $shouldStop()) {
                    return $this->summary(completed: false, reason: 'Stop requested.', externallyStopped: true);
                }

                if ($current->rage < $this->config->stopRage) {
                    return $this->summary(completed: false, reason: "Rage below the {$this->config->stopRage} floor.");
                }

                $target = $this->attacker->findTarget($name);

                if ($target === null) {
                    $log("No player found for '{$name}' — skipping.");

                    break;
                }

                $event = $this->attacker->attack($target, $this->config->attackRage, $this->config->message);
                $this->attacks++;
                $onBattle?->__invoke($event);
                $log($this->line($target->name, $event->outcome));

                $current = $this->stats->refresh();
            }
        }

        return $this->summary(completed: true, reason: 'PvP run complete.');
    }

    private function line(string $opponent, BattleOutcome $outcome): string
    {
        return match ($outcome) {
            BattleOutcome::Win => "Beat {$opponent}.",
            BattleOutcome::Loss => "Lost to {$opponent}.",
            default => "Attack on {$opponent} failed.",
        };
    }

    private function summary(bool $completed, string $reason, bool $externallyStopped = false): PvpRunSummary
    {
        return new PvpRunSummary(
            completed: $completed,
            attacks: $this->attacks,
            stopReason: $reason,
            externallyStopped: $externallyStopped,
        );
    }
}
