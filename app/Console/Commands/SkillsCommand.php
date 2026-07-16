<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:skills
    {action=list : list | select | deselect | show}
    {character? : Character id or name (for select/deselect/show)}
    {--skill= : Skill id or name (for select/deselect)}
    {--school= : Filter the catalog listing by school}')]
#[Description('View the skill catalog and manage a character\'s cast-on-start selection')]
class SkillsCommand extends Command
{
    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list' => $this->list(),
            'select' => $this->setSelected(true),
            'deselect' => $this->setSelected(false),
            'show' => $this->show(),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): int
    {
        $this->error("Unknown action '{$this->argument('action')}'. Use list, select, deselect, or show.");

        return self::FAILURE;
    }

    private function list(): int
    {
        $query = Skill::query()->orderBy('school')->orderBy('name');

        if ($this->option('school') !== null) {
            $query->where('school', $this->option('school'));
        }

        $this->table(
            ['ID', 'Name', 'School', 'Rage', 'Cooldown', 'Duration'],
            $query->get()->map(fn (Skill $s) => [
                $s->id, $s->name, $s->school->value, $s->rage_cost,
                $s->cooldown_minutes !== null ? "{$s->cooldown_minutes}m" : '—',
                $s->duration_minutes !== null ? "{$s->duration_minutes}m" : '—',
            ]),
        );

        return self::SUCCESS;
    }

    private function setSelected(bool $selected): int
    {
        $character = $this->resolveCharacter();
        $skill = $this->resolveSkill();

        if ($character === null || $skill === null) {
            return self::FAILURE;
        }

        CharacterSkill::updateOrCreate(
            ['character_id' => $character->id, 'skill_id' => $skill->id],
            ['cast_on_start' => $selected],
        );

        $this->info(sprintf('%s %s cast-on-start for %s.', $skill->name, $selected ? '→ selected for' : '→ removed from', $character->name));

        return self::SUCCESS;
    }

    private function show(): int
    {
        $character = $this->resolveCharacter();

        if ($character === null) {
            return self::FAILURE;
        }

        $selected = CharacterSkill::with('skill')
            ->where('character_id', $character->id)
            ->where('cast_on_start', true)
            ->get();

        if ($selected->isEmpty()) {
            $this->info("{$character->name} has no cast-on-start skills selected.");

            return self::SUCCESS;
        }

        $this->info("{$character->name} cast-on-start skills:");
        $this->table(
            ['Skill', 'School', 'Active buff?', 'On cooldown?', 'Last cast'],
            $selected->map(fn (CharacterSkill $s) => [
                $s->skill->name,
                $s->skill->school->value,
                $s->isBuffActive() ? 'yes' : 'no',
                $s->isOnCooldown() ? 'yes' : 'no',
                $s->last_cast_at?->diffForHumans() ?? 'never',
            ]),
        );

        return self::SUCCESS;
    }

    private function resolveCharacter(): ?Character
    {
        $identifier = $this->argument('character');

        if ($identifier === null) {
            $this->error('This action needs a character id or name.');

            return null;
        }

        $character = is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();

        if ($character === null) {
            $this->error('Character not found.');
        }

        return $character;
    }

    private function resolveSkill(): ?Skill
    {
        $identifier = $this->option('skill');

        if ($identifier === null) {
            $this->error('Pass --skill={id or name}.');

            return null;
        }

        $skill = is_numeric($identifier)
            ? Skill::find((int) $identifier)
            : Skill::where('name', 'ilike', $identifier)->first();

        if ($skill === null) {
            $this->error("Skill '{$identifier}' not found.");
        }

        return $skill;
    }
}
