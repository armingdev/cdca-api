<?php

namespace App\Console\Commands;

use App\Game\Engine\MobRunConfig;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Jobs\RunMobJob;
use App\Models\Character;
use App\Models\Run;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Signature('outwar:run-start
    {--characters=* : Character ids or names to run}
    {--mob=* : Exact mob name(s) to farm}
    {--stop-rage=2500 : Per-character rage floor}
    {--max-kills=0 : Stop each character after this many wins (0 = unlimited)}
    {--level-up : Level up (refills rage) instead of stopping when rage is low}
    {--restart-every= : Re-dispatch the run every N minutes after it finishes}
    {--start-at= : Delay the first start until this time (e.g. "22:57")}')]
#[Description('Start a mob run for the selected characters (one queued worker each)')]
class RunStartCommand extends Command
{
    public function handle(): int
    {
        $characters = $this->resolveCharacters((array) $this->option('characters'));

        if ($characters->isEmpty()) {
            $this->error('Pass at least one --characters=<id or name>.');

            return self::FAILURE;
        }

        $mobNames = array_values((array) $this->option('mob'));

        if ($mobNames === []) {
            $this->error('Pass at least one --mob="Exact Mob Name".');

            return self::FAILURE;
        }

        $config = new MobRunConfig(
            mobNames: $mobNames,
            stopRage: (int) $this->option('stop-rage'),
            maxKills: (int) $this->option('max-kills'),
            levelUp: (bool) $this->option('level-up'),
        );

        $startAt = $this->option('start-at') !== null
            ? Carbon::parse($this->option('start-at'))
            : null;

        if ($startAt !== null && $startAt->isPast()) {
            $startAt = $startAt->addDay();
        }

        $run = Run::create([
            'mode' => RunMode::Mob,
            'config' => $config->toArray(),
            'status' => RunStatus::Running,
            'restart_every_minutes' => $this->option('restart-every') !== null
                ? (int) $this->option('restart-every')
                : null,
            'start_at' => $startAt,
            'last_started_at' => $startAt ?? now(),
        ]);

        foreach ($characters as $character) {
            $participant = $run->participants()->create(['character_id' => $character->id]);

            $job = new RunMobJob($participant);

            $startAt !== null ? dispatch($job->delay($startAt)) : dispatch($job);
        }

        $this->info(sprintf(
            'Run #%d started for %d character(s): %s%s.',
            $run->id,
            $characters->count(),
            $characters->pluck('name')->implode(', '),
            $startAt !== null ? ' — first start at '.$startAt->toDateTimeString() : '',
        ));
        $this->line("Watch with: php artisan outwar:run-status {$run->id}");

        return self::SUCCESS;
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
