<?php

use App\Game\Enums\SkillSchool;
use App\Models\Skill;
use Database\Seeders\SkillSeeder;

it('seeds the 46-skill catalog with schools, cooldowns and durations', function () {
    (new SkillSeeder)->run();

    expect(Skill::count())->toBe(46);

    $circ = Skill::find(3008);

    expect($circ->name)->toBe('Circumspect')
        ->and($circ->school)->toBe(SkillSchool::Ferocity)
        ->and($circ->rage_cost)->toBe(20)
        ->and($circ->cooldown_minutes)->toBe(720)
        ->and($circ->duration_minutes)->toBe(60);

    // Teleport's duration renders as non-numeric text → null; it maxes at L1.
    expect(Skill::find(27)->duration_minutes)->toBeNull()
        ->and(Skill::find(27)->single_level)->toBeTrue();

    // Street Smarts is a Class skill.
    expect(Skill::find(25)->school)->toBe(SkillSchool::ClassSkill);

    // The Masterful trio unlocks at character level 80.
    expect(Skill::find(3182)->unlock_level)->toBe(80);

    expect(Skill::where('school', SkillSchool::Affliction)->count())->toBe(11)
        ->and(Skill::where('school', SkillSchool::Preservation)->count())->toBe(11);

    // The 3 verified Misc skills: acquired in-game, catalog stores no
    // cooldown/duration (observed values are per-character modified).
    $misc = Skill::where('school', SkillSchool::Misc)->get();

    expect($misc->pluck('id')->sort()->values()->all())->toBe([46, 2996, 3197])
        ->and($misc->firstWhere('id', 46)->cooldown_minutes)->toBeNull();
});
