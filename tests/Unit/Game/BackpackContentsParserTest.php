<?php

use App\Game\Parsers\BackpackContentsParser;

it('parses the captured regular tab: names, stacks, menu flags, capacity', function () {
    $contents = new BackpackContentsParser()->parse(gameFixture('backpack_contents.html'));

    expect($contents->items)->toHaveCount(18)
        ->and($contents->maxSlots)->toBe(30)
        ->and($contents->itemCount)->toBe(18)
        ->and($contents->isOver)->toBeFalse();

    $shield = $contents->items[0];
    expect($shield->iid)->toBe(7680408)
        ->and($shield->name)->toBe('Bone-Forge')
        ->and($shield->qty)->toBe(1)
        ->and($shield->slotIndex)->toBe(0)
        ->and($shield->ownerId)->toBe(7257)
        ->and($shield->menuFlags)->toBe('edzcvs')
        ->and($shield->canEquip())->toBeTrue()
        ->and($shield->equipSlotType)->toBe(1)
        ->and($shield->image)->toBe('/images/items/phoenix_guardian_23.JPG');

    $augment = $contents->items[1];
    expect($augment->name)->toBe('Augment')
        ->and($augment->qty)->toBe(10)
        ->and($augment->menuFlags)->toBe('dzcvs')
        ->and($augment->canEquip())->toBeFalse();

    $key = $contents->itemsNamed('Radiation Prototype')[0];
    expect($key->iid)->toBe(1557857102)
        ->and($key->menuFlags)->toBe('edzcv')
        ->and($key->equipSlotType)->toBe(5);
});

it('parses the captured empty utility tab', function () {
    $contents = new BackpackContentsParser()->parse(gameFixture('backpack_contents_empty.html'));

    expect($contents->items)->toBeEmpty()
        ->and($contents->maxSlots)->toBe(-1)
        ->and($contents->itemCount)->toBe(0)
        ->and($contents->isOver)->toBeFalse();
});
