<?php

use App\Game\Exceptions\ParseException;
use App\Game\Parsers\ItemRolloverParser;

it('parses the captured Bone-Forge tooltip', function () {
    $detail = new ItemRolloverParser()->parse(gameFixture('item_rollover.html'));

    expect($detail->name)->toBe('Bone-Forge')
        ->and($detail->slot)->toBe('Shield')
        ->and($detail->requiredLevel)->toBe(60)
        ->and($detail->stat('arcane'))->toBe(75)
        ->and($detail->stat('kinetic'))->toBe(75)
        // Enhancement bonuses are summed into the value: +550 (+3) HP.
        ->and($detail->stat('hp'))->toBe(553)
        ->and($detail->stat('holy resist'))->toBe(100)
        ->and($detail->stat('arcane resist'))->toBe(75)
        ->and($detail->stat('shadow resist'))->toBe(35)
        ->and($detail->stat('block'))->toBe(10)
        ->and($detail->stat('elemental block'))->toBe(10)
        ->and($detail->stat('rage per hr'))->toBe(290)
        ->and($detail->stat('exp per hr'))->toBe(163)
        ->and($detail->stat('rampage'))->toBe(7)
        ->and($detail->stat('max rage'))->toBe(1510)
        ->and($detail->tradesLeftToday)->toBe(1);
});

it('parses a statless quest item without a slot', function () {
    $detail = new ItemRolloverParser()->parse('<b>Thief Dagger</b><br>A dagger stolen from the church.');

    expect($detail->name)->toBe('Thief Dagger')
        ->and($detail->slot)->toBeNull()
        ->and($detail->requiredLevel)->toBeNull()
        ->and($detail->stats)->toBeEmpty()
        ->and($detail->tradesLeftToday)->toBeNull();
});

it('throws on an empty response', function () {
    new ItemRolloverParser()->parse('');
})->throws(ParseException::class);
