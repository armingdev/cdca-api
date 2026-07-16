<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Engine\QuestRunConfig;
use App\Game\Exceptions\GameException;
use App\Game\Quest\QuestRunner;
use App\Models\Character;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:quest {character : Character id or name}
    {--npc= : Exact quest-giver mob name (must be mapped)}
    {--quest= : Quest id to run}
    {--stop-rage=2500 : Rage floor while farming objectives}
    {--level-up : Level up (refills rage) instead of stopping when rage is low}')]
#[Description('Quest mode: accept a quest at its giver and auto-advance every step to completion')]
class QuestCommand extends Command
{
    public function handle(LoginService $loginService): int
    {
        $character = $this->resolveCharacter($this->argument('character'));

        if ($character === null) {
            $this->error('Character not found.');

            return self::FAILURE;
        }

        if ($this->option('npc') === null || $this->option('quest') === null) {
            $this->error('Pass --npc="Giver Name" and --quest={id}.');

            return self::FAILURE;
        }

        $config = new QuestRunConfig(
            npcName: (string) $this->option('npc'),
            questId: (int) $this->option('quest'),
            stopRage: (int) $this->option('stop-rage'),
            levelUp: (bool) $this->option('level-up'),
        );

        if (! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        $this->info("Running quest {$config->questId} via {$config->npcName} as {$character->name}.");

        try {
            $summary = QuestRunner::forCharacter($character, $config)
                ->run(log: fn (string $message) => $this->line($message));
        } catch (GameException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($summary->stopReason);
        $this->info(sprintf(
            '%s — %d step(s), +%s exp, %d kill(s).',
            $summary->completed ? 'Quest complete' : 'Stopped',
            $summary->stepsCompleted,
            number_format($summary->expGained),
            $summary->kills,
        ));

        return $summary->completed ? self::SUCCESS : self::FAILURE;
    }

    private function resolveCharacter(string $identifier): ?Character
    {
        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }
}
