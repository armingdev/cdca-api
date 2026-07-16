<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Exceptions\GameException;
use App\Game\Quest\QuestListRunner;
use App\Models\Character;
use App\Models\QuestList;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:questlist-run {character : Character id or name}
    {list : Quest list name}
    {--stop-rage=2500 : Rage floor while farming objectives}
    {--level-up : Level up (refills rage) instead of stopping when rage is low}')]
#[Description('Quest-list mode: run every quest in a list in order, skipping already-completed ones')]
class QuestListRunCommand extends Command
{
    public function handle(LoginService $loginService): int
    {
        $character = $this->resolveCharacter($this->argument('character'));

        if ($character === null) {
            $this->error('Character not found.');

            return self::FAILURE;
        }

        $list = QuestList::where('name', $this->argument('list'))->first();

        if ($list === null) {
            $this->error("Quest list '{$this->argument('list')}' not found.");

            return self::FAILURE;
        }

        if (! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        $this->info("Running quest list '{$list->name}' as {$character->name}.");

        try {
            $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(
                questListId: $list->id,
                stopRage: (int) $this->option('stop-rage'),
                levelUp: (bool) $this->option('level-up'),
            ))->run(log: fn (string $message) => $this->line($message));
        } catch (GameException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($summary->stopReason);
        $this->info(sprintf(
            '%s — %d completed, %d skipped, %d kill(s).',
            $summary->completed ? 'List complete' : 'Stopped',
            $summary->questsCompleted,
            $summary->questsSkipped,
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
