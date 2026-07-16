<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Combat\PvpRunner;
use App\Game\Engine\PvpRunConfig;
use App\Models\Character;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:pvp {character : Character id or name}
    {--target=* : Player name(s) to attack, in order}
    {--attack-rage=50 : PvP power per attack (2-50)}
    {--attacks=1 : Attacks per target}
    {--stop-rage=2500 : Character rage-pool floor}
    {--message= : Optional attack message}')]
#[Description('PvP mode: search each target by name and attack it')]
class PvpCommand extends Command
{
    public function handle(LoginService $loginService): int
    {
        $character = $this->resolveCharacter($this->argument('character'));

        if ($character === null) {
            $this->error('Character not found.');

            return self::FAILURE;
        }

        $targets = array_values((array) $this->option('target'));

        if ($targets === []) {
            $this->error('Pass at least one --target="PlayerName".');

            return self::FAILURE;
        }

        if (! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        $this->info(sprintf('PvP as %s against: %s.', $character->name, implode(', ', $targets)));

        $summary = PvpRunner::forCharacter($character, new PvpRunConfig(
            targets: $targets,
            attackRage: (int) $this->option('attack-rage'),
            attacksPerTarget: (int) $this->option('attacks'),
            stopRage: (int) $this->option('stop-rage'),
            message: (string) ($this->option('message') ?? ''),
        ))->run(log: fn (string $message) => $this->line($message));

        $this->info($summary->stopReason);
        $this->info(sprintf('%s — %d attack(s).', $summary->completed ? 'Done' : 'Stopped', $summary->attacks));

        return $summary->completed ? self::SUCCESS : self::FAILURE;
    }

    private function resolveCharacter(string $identifier): ?Character
    {
        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }
}
