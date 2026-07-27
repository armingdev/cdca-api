<?php

use App\Game\Exceptions\ParseException;
use App\Game\Parsers\WorldQuestHelperParser;

it('parses kill and collect "find my target" toggles from the tracker', function () {
    $toggles = new WorldQuestHelperParser()->parse(gameFixture('quest/world_questhelper_toggles.json'));

    expect(count($toggles))->toBeGreaterThanOrEqual(2);

    $kill = collect($toggles)->firstWhere('questId', 124);

    expect($kill->mobId)->toBe(390)
        ->and($kill->itemName)->toBe('')
        ->and($kill->isCollect())->toBeFalse()
        ->and($kill->stepId)->toBe(344)
        ->and($kill->conditionId)->toBe(6751);

    $collect = collect($toggles)->firstWhere('questId', 125);

    expect($collect->mobId)->toBe(0)
        ->and($collect->itemName)->toBe('Uncut Emerald')
        ->and($collect->isCollect())->toBeTrue()
        ->and($collect->stepId)->toBe(346)
        ->and($collect->conditionId)->toBe(1816);
});

it('throws on a body without a qtable', function () {
    new WorldQuestHelperParser()->parse('<html>login</html>');
})->throws(ParseException::class);
