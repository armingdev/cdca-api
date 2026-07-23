<?php

use App\Game\Exceptions\ParseException;
use App\Game\Parsers\SkillInfoParser;

it('parses a trained recharging skill with level-scaled values', function () {
    $info = (new SkillInfoParser)->parse(gameFixture('skills/skills_info_trained_recharging.html'));

    expect($info->name)->toBe('Empower')
        ->and($info->level)->toBe(9)
        ->and($info->rageCost)->toBe(90)
        ->and($info->cooldownMinutes)->toBe(120)
        ->and($info->durationMinutes)->toBe(180)
        ->and($info->rechargingMinutesRemaining)->toBe(111)
        ->and($info->hasNextLevel)->toBeTrue()
        ->and($info->learned)->toBeTrue();
});

it('parses a misc skill with modifier-adjusted values', function () {
    $info = (new SkillInfoParser)->parse(gameFixture('skills/skills_info_misc_triworld.html'));

    expect($info->name)->toBe('Triworld Influence')
        ->and($info->level)->toBe(1)
        ->and($info->rageCost)->toBe(500)
        ->and($info->cooldownMinutes)->toBe(1296)
        ->and($info->durationMinutes)->toBe(264)
        ->and($info->rechargingMinutesRemaining)->toBe(884)
        ->and($info->hasNextLevel)->toBeTrue();
});

it('parses an unlearned idle skill', function () {
    $body = <<<'HTML'
        <div class="text-left">
            <h5>Boost Level 1</h5>
            You grow to an enormous size.</div>
        <b>Rage Cost:</b><br>
        10<br>
        <b>Cooldown:</b><br>
            120 mins<br>
        <b>Duration:</b><br>
            60 mins<br>
        You have not learned this skill yet
        HTML;

    $info = (new SkillInfoParser)->parse($body);

    expect($info->learned)->toBeFalse()
        ->and($info->rechargingMinutesRemaining)->toBeNull()
        ->and($info->hasNextLevel)->toBeFalse()
        ->and($info->rageCost)->toBe(10);
});

it('tolerates a missing duration', function () {
    $body = '<h5>Teleport Level 1</h5>desc</div><b>Rage Cost:</b><br>100<br><b>Cooldown:</b><br>60 mins<br><b>Duration:</b><br>&mdash;<br>';

    $info = (new SkillInfoParser)->parse($body);

    expect($info->durationMinutes)->toBeNull()
        ->and($info->cooldownMinutes)->toBe(60);
});

it('throws on an unexpected response', function () {
    (new SkillInfoParser)->parse('<html>Rampid Gaming Login</html>');
})->throws(ParseException::class);
