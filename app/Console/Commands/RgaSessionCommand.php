<?php

namespace App\Console\Commands;

use App\Models\Rga;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:rga-session {rga : RGA id or username}')]
#[Description('Reveal the RGA\'s stored session cookies (rg_sess_id etc.) for reuse in a browser')]
class RgaSessionCommand extends Command
{
    public function handle(): int
    {
        $rga = $this->resolveRga($this->argument('rga'));

        if ($rga === null) {
            $this->error('RGA not found.');

            return self::FAILURE;
        }

        if (empty($rga->cookies['rg_sess_id'] ?? null)) {
            $this->error('No session captured for this RGA.');

            return self::FAILURE;
        }

        $rows = [];

        foreach (['rg_sess_id', 'token', 'cuserid2'] as $name) {
            if (! empty($rga->cookies[$name])) {
                $rows[] = [$name, $rga->cookies[$name]];
            }
        }

        $rows[] = ['status', $rga->status];

        $this->table(['Cookie', 'Value'], $rows);

        return self::SUCCESS;
    }

    private function resolveRga(string $identifier): ?Rga
    {
        return is_numeric($identifier)
            ? Rga::find((int) $identifier)
            : Rga::where('username', $identifier)->first();
    }
}
