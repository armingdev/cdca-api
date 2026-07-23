<?php

namespace Database\Seeders;

use App\Game\Enums\SkillSchool;
use App\Models\Skill;
use Illuminate\Database\Seeder;

/**
 * Imports the 43-skill catalog (database/data/skills.json, captured via
 * skills_info.php). The JSON carries name/rage/cooldown/duration but not the
 * school, so the school → skill-id map (from docs/game-api/skills.md) is
 * applied here.
 */
class SkillSeeder extends Seeder
{
    /** Skills gated behind character level 80 (the Masterful trio). */
    private const array UNLOCK_AT_80 = [3182, 3183, 3184];

    /** Skills that max at level 1 (their Train link disappears once trained). */
    private const array SINGLE_LEVEL = [27];

    /**
     * @var array<string, list<int>>
     */
    private const array SCHOOLS = [
        SkillSchool::ClassSkill->value => [3, 4, 7, 27, 22, 28, 25, 3182, 3183, 3184],
        SkillSchool::Ferocity->value => [9, 26, 29, 312, 87, 3024, 17, 3008, 5, 3007, 3199],
        SkillSchool::Preservation->value => [3013, 3014, 3010, 3015, 3009, 2, 3011, 3012, 3025, 3006, 3200],
        SkillSchool::Affliction->value => [36, 33, 35, 8, 10, 16, 14, 21, 3016, 3017, 3201],
    ];

    public function run(): void
    {
        $catalog = json_decode(file_get_contents(database_path('data/skills.json')), true);
        $schoolById = $this->schoolById();

        foreach ($catalog as $id => $skill) {
            $id = (int) $id;

            Skill::updateOrCreate(['id' => $id], [
                'name' => $skill['name'],
                'school' => $schoolById[$id] ?? SkillSchool::Misc->value,
                'rage_cost' => (int) $skill['rage'],
                'cooldown_minutes' => $this->minutes($skill['cooldown'] ?? null),
                'duration_minutes' => $this->minutes($skill['duration'] ?? null),
                'unlock_level' => in_array($id, self::UNLOCK_AT_80, true) ? 80 : null,
                'single_level' => in_array($id, self::SINGLE_LEVEL, true),
                'description' => $skill['desc'] ?? null,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function schoolById(): array
    {
        $map = [];

        foreach (self::SCHOOLS as $school => $ids) {
            foreach ($ids as $id) {
                $map[$id] = $school;
            }
        }

        return $map;
    }

    /**
     * Parse "600 mins" → 600; non-numeric values (e.g. Teleport's "You have")
     * → null.
     */
    private function minutes(?string $value): ?int
    {
        return $value !== null && preg_match('/^(\d+)\s*min/', $value, $m) ? (int) $m[1] : null;
    }
}
