<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Exceptions\LoginFailedException;
use App\Models\Rga;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:rga-login {rga : RGA id or username}')]
#[Description('Log an RGA in to the game and capture its session cookies')]
class RgaLoginCommand extends Command
{
    public function handle(LoginService $loginService): int
    {
        $rga = $this->resolveRga($this->argument('rga'));

        if ($rga === null) {
            $this->error('RGA not found.');

            return self::FAILURE;
        }

        try {
            $rga = $loginService->login($rga);
        } catch (LoginFailedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Logged in. Session captured for RGA #{$rga->id} ({$rga->username}).");
        $this->line('Next: php artisan outwar:characters-sync '.$rga->id);

        return self::SUCCESS;
    }

    private function resolveRga(string $identifier): ?Rga
    {
        return is_numeric($identifier)
            ? Rga::find((int) $identifier)
            : Rga::where('username', $identifier)->first();
    }
}
