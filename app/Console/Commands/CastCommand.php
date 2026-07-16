<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Skills\SkillCaster;
use App\Models\Character;
use App\Models\Skill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:cast {character : Character id or name}
    {--skill= : Cast one skill now, by id or name}
    {--on-start : Cast the character\'s selected cast-on-start skills}')]
#[Description('Cast a skill (or the character\'s cast-on-start set) now')]
class CastCommand extends Command
{
    public function handle(LoginService $loginService): int
    {
        $character = $this->resolveCharacter($this->argument('character'));

        if ($character === null) {
            $this->error('Character not found.');

            return self::FAILURE;
        }

        if ($this->option('skill') === null && ! $this->option('on-start')) {
            $this->error('Pass --skill={id or name} or --on-start.');

            return self::FAILURE;
        }

        if (! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        $caster = SkillCaster::forCharacter($character);

        if ($this->option('on-start')) {
            $cast = $caster->castOnStart(log: fn (string $m) => $this->line($m));
            $this->info("Cast {$cast} cast-on-start skill(s).");

            return self::SUCCESS;
        }

        $skill = $this->resolveSkill((string) $this->option('skill'));

        if ($skill === null) {
            $this->error("Skill '{$this->option('skill')}' not found.");

            return self::FAILURE;
        }

        if ($caster->cast($skill)) {
            $this->info("Cast {$skill->name}.");

            return self::SUCCESS;
        }

        $this->error("Failed to cast {$skill->name} (rage too low, on cooldown, or not learned).");

        return self::FAILURE;
    }

    private function resolveCharacter(string $identifier): ?Character
    {
        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }

    private function resolveSkill(string $identifier): ?Skill
    {
        return is_numeric($identifier)
            ? Skill::find((int) $identifier)
            : Skill::where('name', 'ilike', $identifier)->first();
    }
}
