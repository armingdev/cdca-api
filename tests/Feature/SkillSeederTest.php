<?php

use App\Game\Enums\SkillSchool;
use App\Models\Skill;
use Database\Seeders\SkillSeeder;

it('seeds the 43-skill catalog with schools, cooldowns and durations', function () {
    (new SkillSeeder)->run();

    expect(Skill::count())->toBe(43);

    $circ = Skill::find(3008);

    expect($circ->name)->toBe('Circumspect')
        ->and($circ->school)->toBe(SkillSchool::Ferocity)
        ->and($circ->rage_cost)->toBe(20)
        ->and($circ->cooldown_minutes)->toBe(720)
        ->and($circ->duration_minutes)->toBe(60);

    // Teleport's duration renders as non-numeric text → null.
    expect(Skill::find(27)->duration_minutes)->toBeNull();

    // Street Smarts is a Class skill.
    expect(Skill::find(25)->school)->toBe(SkillSchool::ClassSkill);

    expect(Skill::where('school', SkillSchool::Affliction)->count())->toBe(11)
        ->and(Skill::where('school', SkillSchool::Preservation)->count())->toBe(11);
});
