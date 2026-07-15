<?php

namespace App\Console\Commands;

use App\Game\Auth\CharacterSyncService;
use App\Game\Auth\LoginService;
use App\Models\Rga;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:characters-sync {rga : RGA id or username}')]
#[Description('Discover and upsert all characters on an RGA (both servers)')]
class CharactersSyncCommand extends Command
{
    public function handle(CharacterSyncService $syncService, LoginService $loginService): int
    {
        $rga = $this->resolveRga($this->argument('rga'));

        if ($rga === null) {
            $this->error('RGA not found.');

            return self::FAILURE;
        }

        if (! $rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $rga = $loginService->login($rga);
        }

        $characters = $syncService->sync($rga);

        $this->table(
            ['ID', 'Server', 'Suid', 'Name', 'Level', 'Crew'],
            $characters->map(fn ($character) => [
                $character->id,
                config("outwar.servers.{$character->server_id}.name"),
                $character->suid,
                $character->name,
                $character->level,
                $character->crew,
            ]),
        );

        $this->info("{$characters->count()} characters synced for RGA #{$rga->id}.");

        return self::SUCCESS;
    }

    private function resolveRga(string $identifier): ?Rga
    {
        return is_numeric($identifier)
            ? Rga::find((int) $identifier)
            : Rga::where('username', $identifier)->first();
    }
}
