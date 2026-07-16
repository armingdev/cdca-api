<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Exceptions\GameException;
use App\Models\Character;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:attack {character : Character id or name}
    {--mob=* : Exact mob name(s) to farm}
    {--stop-rage=2500 : Stop (or level) when rage drops below this floor}
    {--max-kills=0 : Stop after this many wins (0 = unlimited)}
    {--level-up : When rage is low, try levelup.php (refills rage) before stopping}')]
#[Description('Mob mode: pathfind to the target mobs\' rooms and attack-loop until a stop condition')]
class AttackCommand extends Command
{
    public function handle(LoginService $loginService): int
    {
        $character = $this->resolveCharacter($this->argument('character'));

        if ($character === null) {
            $this->error('Character not found.');

            return self::FAILURE;
        }

        $config = new MobRunConfig(
            mobNames: array_values((array) $this->option('mob')),
            stopRage: (int) $this->option('stop-rage'),
            maxKills: (int) $this->option('max-kills'),
            levelUp: (bool) $this->option('level-up'),
        );

        if ($config->mobNames === []) {
            $this->error('Pass at least one --mob="Exact Mob Name".');

            return self::FAILURE;
        }

        if (! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        $this->info(sprintf(
            'Farming %s as %s (stop below %d rage).',
            implode(', ', $config->mobNames),
            $character->name,
            $config->stopRage,
        ));

        try {
            $summary = MobRunner::forCharacter($character, $config)
                ->run(log: fn (string $message) => $this->line($message));
        } catch (GameException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($summary->stopReason);
        $this->info(sprintf('Done: %d wins / %d losses / %d errors.', $summary->wins, $summary->losses, $summary->errors));

        return self::SUCCESS;
    }

    private function resolveCharacter(string $identifier): ?Character
    {
        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }
}
