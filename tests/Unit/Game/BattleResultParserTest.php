<?php

use App\Game\Enums\BattleOutcome;
use App\Game\Exceptions\ParseException;
use App\Game\Parsers\BattleResultParser;

it('parses the captured win fixture', function () {
    $result = new BattleResultParser()->parse(gameFixture('battle_result_vars.js'));

    expect($result->outcome)->toBe(BattleOutcome::Win)
        ->and($result->attackerName)->toBe('RealLinuXX')
        ->and($result->defenderName)->toBe('Pristine Blader')
        ->and($result->expGained)->toBe(1001)
        ->and($result->goldGained)->toBe(125)
        ->and($result->statGains)->toBe(['strength' => 15])
        ->and($result->dropName)->toBeNull();
});

it('classifies a loss by the weakened-with-no-exp rule from the captured loss', function () {
    // battle_result text from samples/battle_outcomes.json (captured loss).
    $html = 'var battle_result = "Grand Sole Protector has weakened LinuXX_2 by 2";'
        .'var attacker_result = "Win!";'; // the lying template default

    $result = new BattleResultParser()->parse($html);

    expect($result->outcome)->toBe(BattleOutcome::Loss)
        ->and($result->expGained)->toBeNull();
});

it('does not classify a win with incidental weakening as a loss', function () {
    $html = 'var battle_result = "Mob has weakened Hero by 3<br>Hero has gained 676 experience!";';

    $result = new BattleResultParser()->parse($html);

    expect($result->outcome)->toBe(BattleOutcome::Win)
        ->and($result->expGained)->toBe(676);
});

it('extracts drops from the found_items div', function () {
    $html = gameFixture('battle_result_vars.js')
        .'<div id="found_items"><font size="3"><b>WIN: Found Thief Dagger</b></font></div>';

    expect(new BattleResultParser()->parse($html)->dropName)->toBe('Thief Dagger');
});

it('marks unclassifiable battle text as unknown', function () {
    $result = new BattleResultParser()->parse('var battle_result = "Something entirely new happened";');

    expect($result->outcome)->toBe(BattleOutcome::Unknown);
});

it('throws when the page has no battle_result var', function () {
    new BattleResultParser()->parse('<html>That mob is already dead!</html>');
})->throws(ParseException::class);
