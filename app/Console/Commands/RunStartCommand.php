<?php

namespace App\Console\Commands;

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\PvpRunConfig;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunLauncher;
use App\Game\Enums\RunMode;
use App\Game\Exceptions\CharactersBusyException;
use App\Models\Character;
use App\Models\QuestList;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Signature('outwar:run-start
    {--characters=* : Character ids or names to run}
    {--mode=mob : Run mode: mob, quest, quest-list, or pvp}
    {--mob=* : (mob mode) Exact mob name(s) to farm}
    {--npc= : (quest mode) Exact quest-giver mob name}
    {--quest= : (quest mode) Quest id to run}
    {--list= : (quest-list mode) Quest list name}
    {--target=* : (pvp mode) Player name(s) to attack}
    {--crew= : (pvp crew-members mode) Crew id to pull members from}
    {--auto-enter-brawl : (pvp brawl modes) Register for the round if not entered}
    {--attacks=1 : (pvp mode) Attacks per target}
    {--message= : (pvp mode) Optional attack message}
    {--stop-rage=2500 : Per-character rage floor}
    {--max-kills=0 : (mob mode) Stop each character after this many wins (0 = unlimited)}
    {--run-count=0 : (mob mode) Full passes per character (0 = farm indefinitely, waiting out respawns)}
    {--attack-interval= : (mob mode) Seconds to wait between passes (min 60)}
    {--respawn-wait= : (quest modes) Seconds to wait for mob respawns before retrying an objective (min 60)}
    {--level-up : Level up (refills rage) instead of stopping when rage is low}
    {--smart : Smart mode: auto-equip backpack gear, level up after a loss, stop when outmatched}
    {--cast-on-start : Cast the character\'s selected skills before the run begins}
    {--require-circ : Only run while Circumspect is active (cast it if possible, else gate off)}
    {--restart-every= : Re-dispatch the run every N minutes after it finishes}
    {--start-at= : Delay the first start until this time (e.g. "22:57")}')]
#[Description('Start a mob or quest run for the selected characters (one queued worker each)')]
class RunStartCommand extends Command
{
    public function handle(RunLauncher $launcher): int
    {
        $characters = $this->resolveCharacters((array) $this->option('characters'));

        if ($characters->isEmpty()) {
            $this->error('Pass at least one --characters=<id or name>.');

            return self::FAILURE;
        }

        $mode = RunMode::tryFrom((string) $this->option('mode'));

        if ($mode === null) {
            $this->error('--mode must be mob, quest, quest-list, or pvp.');

            return self::FAILURE;
        }

        $config = $this->buildConfig($mode);

        if ($config === null) {
            return self::FAILURE;
        }

        $startAt = $this->option('start-at') !== null ? Carbon::parse($this->option('start-at')) : null;

        try {
            $run = $launcher->launch(
                mode: $mode,
                characters: $characters,
                config: $config,
                castOnStart: (bool) $this->option('cast-on-start'),
                requireCircumspect: (bool) $this->option('require-circ'),
                restartEveryMinutes: $this->option('restart-every') !== null ? (int) $this->option('restart-every') : null,
                startAt: $startAt,
            );
        } catch (CharactersBusyException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $startAt = $run->start_at;

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
            RunMode::PvpAttackList => $this->buildPvpConfig(),
            RunMode::PvpCrewHitlist,
            RunMode::PvpCrewMembers,
            RunMode::PvpBrawl,
            RunMode::PvpFactionBrawl => $this->buildPvpConfig(requireTargets: false),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPvpConfig(bool $requireTargets = true): ?array
    {
        $targets = array_values((array) $this->option('target'));

        if ($requireTargets && $targets === []) {
            $this->error('PvP attack-list mode needs at least one --target="PlayerName".');

            return null;
        }

        return (new PvpRunConfig(
            targets: $targets,
            crewGameId: $this->option('crew') !== null ? (int) $this->option('crew') : null,
            attacksPerTarget: (int) $this->option('attacks'),
            stopRage: (int) $this->option('stop-rage'),
            message: (string) ($this->option('message') ?? ''),
            autoEnterBrawl: (bool) $this->option('auto-enter-brawl'),
        ))->toArray();
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
            runCount: (int) $this->option('run-count'),
            attackIntervalSeconds: $this->option('attack-interval') !== null
                ? max((int) $this->option('attack-interval'), 60)
                : null,
            smart: (bool) $this->option('smart'),
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
            smart: (bool) $this->option('smart'),
            respawnWaitSeconds: $this->respawnWaitSeconds(),
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
            smart: (bool) $this->option('smart'),
            respawnWaitSeconds: $this->respawnWaitSeconds(),
        ))->toArray();
    }

    private function respawnWaitSeconds(): int
    {
        return $this->option('respawn-wait') !== null
            ? max((int) $this->option('respawn-wait'), 60)
            : QuestRunConfig::DEFAULT_RESPAWN_WAIT_SECONDS;
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
