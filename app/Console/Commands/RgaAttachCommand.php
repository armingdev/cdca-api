<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Exceptions\LoginFailedException;
use App\Models\Rga;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\text;

#[Signature('outwar:rga-attach {rga : RGA id or username} {--session= : rg_sess_id cookie value} {--token= : token cookie value} {--cuserid2= : cuserid2 cookie value}')]
#[Description('Attach a browser rg_sess_id session to an RGA without a credential login')]
class RgaAttachCommand extends Command
{
    private const string SESSION_ID_PATTERN = '/^[0-9a-fA-F]{32}$/';

    public function handle(LoginService $loginService): int
    {
        $rga = $this->resolveRga($this->argument('rga'));

        if ($rga === null) {
            $this->error('RGA not found.');

            return self::FAILURE;
        }

        $sessionId = $this->option('session') ?? text(
            label: 'rg_sess_id cookie value',
            hint: 'Copy it from your browser (DevTools → Application → Cookies → .outwar.com).',
            required: true,
            validate: fn (string $value): ?string => preg_match(self::SESSION_ID_PATTERN, $value) === 1
                ? null
                : 'Must be 32 hex characters.',
        );

        if (preg_match(self::SESSION_ID_PATTERN, $sessionId) !== 1) {
            $this->error('The session id must be 32 hex characters.');

            return self::FAILURE;
        }

        try {
            $rga = $loginService->attachSession($rga, $sessionId, $this->option('token'), $this->option('cuserid2'));
        } catch (LoginFailedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Session attached for RGA #{$rga->id} ({$rga->username}).");
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
