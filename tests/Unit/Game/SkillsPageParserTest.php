<?php

use App\Game\Parsers\SkillsPageParser;

it('parses the Class tab rows with trained, bonus, and gated levels', function () {
    $page = (new SkillsPageParser)->parse(gameFixture('skills/cast_skills_page.html'));

    $rows = collect($page->rows)->keyBy('id');

    expect($rows)->toHaveCount(10);

    $empower = $rows[3];
    expect($empower->name)->toBe('Empower')
        ->and($empower->trainedLevel)->toBe(1)
        ->and($empower->bonusLevel)->toBe(8)
        ->and($empower->effectiveLevel())->toBe(9)
        ->and($empower->isCastable())->toBeTrue()
        ->and($empower->trainable)->toBeTrue();

    $onGuard = $rows[7];
    expect($onGuard->name)->toBe('On Guard')
        ->and($onGuard->trainedLevel)->toBe(0)
        ->and($onGuard->bonusLevel)->toBe(8)
        ->and($onGuard->isCastable())->toBeFalse()
        ->and($onGuard->trainable)->toBeTrue();

    $teleport = $rows[27];
    expect($teleport->trainedLevel)->toBe(1)
        ->and($teleport->bonusLevel)->toBe(0)
        ->and($teleport->trainable)->toBeFalse();

    $masterful = $rows[3182];
    expect($masterful->name)->toBe('Masterful Ferocity')
        ->and($masterful->unlockLevel)->toBe(80)
        ->and($masterful->isCastable())->toBeFalse()
        ->and($masterful->trainable)->toBeFalse();
});

it('parses skill points from the toolbar popup', function () {
    $page = (new SkillsPageParser)->parse(gameFixture('skills/cast_skills_page.html'));

    expect($page->skillPoints)->toBe(15);
});

it('parses the Current Effects and Cast Skills panels', function () {
    $page = (new SkillsPageParser)->parse(gameFixture('skills/cast_skills_page.html'));

    expect($page->currentEffects)->toHaveCount(1)
        ->and($page->currentEffects[0]->name)->toBe('Empower')
        ->and($page->currentEffects[0]->level)->toBe(9)
        ->and($page->currentEffects[0]->minutesLeft)->toBe(171)
        ->and($page->currentEffects[0]->castBy)->toBe('PLAYER');

    expect($page->castSkills)->toHaveCount(1)
        ->and($page->castSkills[0]->name)->toBe('Empower')
        ->and($page->castSkills[0]->minutesLeft)->toBe(171)
        ->and($page->castSkills[0]->castOn)->toBe('PLAYER');
});

it('parses the skill history log', function () {
    $page = (new SkillsPageParser)->parse(gameFixture('skills/cast_skills_page.html'));

    expect($page->history)->toHaveCount(2)
        ->and($page->history[0]->action)->toBe('Cast Empower on PLAYER')
        ->and($page->history[1]->action)->toBe('Trained Teleport Level 1');
});

it('parses the Misc tab as acquired-only skills without Train links', function () {
    $page = (new SkillsPageParser)->parse(gameFixture('skills/cast_skills_misc_tab.html'));

    $rows = collect($page->rows)->keyBy('id');

    expect($rows)->toHaveCount(3)
        ->and($rows->keys()->all())->toBe([46, 2996, 3197])
        ->and($rows[46]->name)->toBe('Shield Wall');

    foreach ($page->rows as $row) {
        expect($row->trainedLevel)->toBe(1)
            ->and($row->trainable)->toBeFalse()
            ->and($row->isCastable())->toBeTrue();
    }
});
