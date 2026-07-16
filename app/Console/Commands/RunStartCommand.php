<?php

namespace App\Console\Commands;

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunDispatcher;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\QuestList;
use App\Models\Run;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Signature('outwar:run-start
    {--characters=* : Character ids or names to run}
    {--mode=mob : Run mode: mob, quest, or quest-list}
    {--mob=* : (mob mode) Exact mob name(s) to farm}
    {--npc= : (quest mode) Exact quest-giver mob name}
    {--quest= : (quest mode) Quest id to run}
    {--list= : (quest-list mode) Quest list name}
    {--stop-rage=2500 : Per-character rage floor}
    {--max-kills=0 : (mob mode) Stop each character after this many wins (0 = unlimited)}
    {--level-up : Level up (refills rage) instead of stopping when rage is low}
    {--restart-every= : Re-dispatch the run every N minutes after it finishes}
    {--start-at= : Delay the first start until this time (e.g. "22:57")}')]
#[Description('Start a mob or quest run for the selected characters (one queued worker each)')]
class RunStartCommand extends Command
{
    public function handle(RunDispatcher $dispatcher): int
    {
        $characters = $this->resolveCharacters((array) $this->option('characters'));

        if ($characters->isEmpty()) {
            $this->error('Pass at least one --characters=<id or name>.');

            return self::FAILURE;
        }

        $mode = RunMode::tryFrom((string) $this->option('mode'));

        if ($mode === null) {
            $this->error('--mode must be "mob" or "quest".');

            return self::FAILURE;
        }

        $config = $this->buildConfig($mode);

        if ($config === null) {
            return self::FAILURE;
        }

        $startAt = $this->option('start-at') !== null ? Carbon::parse($this->option('start-at')) : null;

        if ($startAt !== null && $startAt->isPast()) {
            $startAt = $startAt->addDay();
        }

        $run = Run::create([
            'mode' => $mode,
            'config' => $config,
            'status' => RunStatus::Running,
            'restart_every_minutes' => $this->option('restart-every') !== null ? (int) $this->option('restart-every') : null,
            'start_at' => $startAt,
            'last_started_at' => $startAt ?? now(),
        ]);

        foreach ($characters as $character) {
            $participant = $run->participants()->create(['character_id' => $character->id]);
            $dispatcher->dispatch($participant, $startAt);
        }

        $this->info(sprintf(
            'Run #%d [%s] started for %d character(s): %s%s.',
            $run->id,
            $mode->value,
            $characters->count(),
            $characters->pluck('name')->implode(', '),
            $startAt !== null ? ' — first start at '.$startAt->toDateTimeString() : '',
        ));
        $this->line("Watch with: php artisan outwar:run-status {$run->id}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildConfig(RunMode $mode): ?array
    {
        return match ($mode) {
            RunMode::Mob => $this->buildMobConfig(),
            RunMode::Quest => $this->buildQuestConfig(),
            RunMode::QuestList => $this->buildQuestListConfig(),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildMobConfig(): ?array
    {
        $mobNames = array_values((array) $this->option('mob'));

        if ($mobNames === []) {
            $this->error('Mob mode needs at least one --mob="Exact Mob Name".');

            return null;
        }

        return (new MobRunConfig(
            mobNames: $mobNames,
            stopRage: (int) $this->option('stop-rage'),
            maxKills: (int) $this->option('max-kills'),
            levelUp: (bool) $this->option('level-up'),
        ))->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildQuestConfig(): ?array
    {
        if ($this->option('npc') === null || $this->option('quest') === null) {
            $this->error('Quest mode needs --npc="Giver Name" and --quest={id}.');

            return null;
        }

        return (new QuestRunConfig(
            npcName: (string) $this->option('npc'),
            questId: (int) $this->option('quest'),
            stopRage: (int) $this->option('stop-rage'),
            levelUp: (bool) $this->option('level-up'),
        ))->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildQuestListConfig(): ?array
    {
        if ($this->option('list') === null) {
            $this->error('Quest-list mode needs --list="List Name".');

            return null;
        }

        $list = QuestList::where('name', $this->option('list'))->first();

        if ($list === null) {
            $this->error("Quest list '{$this->option('list')}' not found.");

            return null;
        }

        return (new QuestListRunConfig(
            questListId: $list->id,
            stopRage: (int) $this->option('stop-rage'),
            levelUp: (bool) $this->option('level-up'),
        ))->toArray();
    }

    /**
     * @param  list<string>  $identifiers
     * @return Collection<int, Character>
     */
    private function resolveCharacters(array $identifiers): Collection
    {
        return collect($identifiers)
            ->map(fn (string $identifier) => is_numeric($identifier)
                ? Character::find((int) $identifier)
                : Character::where('name', $identifier)->first())
            ->filter()
            ->unique('id')
            ->values();
    }
}
