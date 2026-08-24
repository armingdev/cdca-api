<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Combat\PvpRunner;
use App\Game\Combat\Targets\PvpTargetSourceFactory;
use App\Game\Engine\PvpRunConfig;
use App\Game\Enums\RunMode;
use App\Models\Character;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:pvp {character : Character id or name}
    {--target=* : Player name(s) to attack, in order}
    {--crew-hitlist : Pull targets from the crew hitlist instead of --target}
    {--crew= : Pull targets from this crew id instead of --target}
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
        $mode = $this->targetMode();

        if ($mode === RunMode::PvpAttackList && $targets === []) {
            $this->error('Pass at least one --target="PlayerName", or use --crew-hitlist / --crew=.');

            return self::FAILURE;
        }

        if (! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        $config = new PvpRunConfig(
            targets: $targets,
            crewGameId: $this->option('crew') !== null ? (int) $this->option('crew') : null,
            attacksPerTarget: (int) $this->option('attacks'),
            stopRage: (int) $this->option('stop-rage'),
            message: (string) ($this->option('message') ?? ''),
        );

        $source = PvpTargetSourceFactory::for($character, $mode, $config);

        $this->info(sprintf('PvP as %s from the %s.', $character->name, $source->label()));

        $summary = PvpRunner::forCharacter($character, $config, $source)
            ->run(log: fn (string $message) => $this->line($message));

        $this->info($summary->stopReason);
        $this->info(sprintf(
            '%s — %d attack(s), %dW/%dL, %d skipped on cooldown.',
            $summary->completed ? 'Done' : 'Stopped',
            $summary->attacks,
            $summary->wins,
            $summary->losses,
            $summary->skippedOnCooldown,
        ));

        return $summary->completed ? self::SUCCESS : self::FAILURE;
    }

    private function targetMode(): RunMode
    {
        if ($this->option('crew-hitlist')) {
            return RunMode::PvpCrewHitlist;
        }

        return $this->option('crew') !== null ? RunMode::PvpCrewMembers : RunMode::PvpAttackList;
    }

    private function resolveCharacter(string $identifier): ?Character
    {
        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }
}
