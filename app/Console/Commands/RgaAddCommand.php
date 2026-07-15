<?php

namespace App\Console\Commands;

use App\Models\Rga;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('outwar:rga-add')]
#[Description('Register a Rampid Gaming Account (credentials are stored encrypted)')]
class RgaAddCommand extends Command
{
    public function handle(): int
    {
        $username = text(
            label: 'RGA username',
            required: true,
            validate: fn (string $value): ?string => Rga::where('username', $value)->exists()
                ? 'This RGA is already registered.'
                : null,
        );

        $password = password(label: 'RGA password', required: true);

        $rga = Rga::create([
            'username' => $username,
            'password' => $password,
        ]);

        $this->info("RGA #{$rga->id} ({$rga->username}) registered.");
        $this->line('Next: php artisan outwar:rga-login '.$rga->id);

        return self::SUCCESS;
    }
}
