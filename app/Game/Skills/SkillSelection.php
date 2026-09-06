<?php

namespace App\Game\Skills;

use App\Models\Character;
use App\Models\CharacterSkill;

/**
 * Writes a character's cast-on-start selection — the set BuffEnsurer keeps
 * active for the duration of a run.
 *
 * Shared by the per-character API endpoint, the console command, and the run
 * launcher (which applies one selection across a whole fleet), so the
 * "upsert the chosen ids, clear everything else" pair has one definition.
 */
class SkillSelection
{
    /**
     * Replace the character's selection with exactly these skill ids.
     *
     * Rows for skills the character has never synced are created on demand:
     * a selection is a statement of intent, and the engine decides per cast
     * whether the skill is trained (it reports `untrained` and moves on).
     *
     * @param  list<int>  $skillIds
     * @return list<int> the ids actually stored, deduplicated
     */
    public function replaceFor(Character $character, array $skillIds): array
    {
        $selected = array_values(array_unique($skillIds));

        if ($selected !== []) {
            $character->skills()->upsert(
                array_map(fn (int $skillId): array => [
                    'character_id' => $character->id,
                    'skill_id' => $skillId,
                    'cast_on_start' => true,
                ], $selected),
                ['character_id', 'skill_id'],
                ['cast_on_start'],
            );
        }

        $character->skills()->whereNotIn('skill_id', $selected)->update(['cast_on_start' => false]);

        return $selected;
    }

    /**
     * Turn one skill on or off without disturbing the rest of the selection.
     */
    public function set(Character $character, int $skillId, bool $selected): void
    {
        CharacterSkill::updateOrCreate(
            ['character_id' => $character->id, 'skill_id' => $skillId],
            ['cast_on_start' => $selected],
        );
    }
}
