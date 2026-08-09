<?php

use App\Game\Data\EquipmentSet;
use App\Game\Parsers\EquipmentPageParser;

beforeEach(function () {
    $this->parser = new EquipmentPageParser;
});

it('reads every worn item with its iid, name and slot', function () {
    $set = $this->parser->parse(gameFixture('equipment_page.html'));

    $weapon = $set->itemsIn(3)[0];

    expect($set->all())->toHaveCount(20)
        ->and($weapon->iid)->toBe(1725164029)
        ->and($weapon->name)->toBe('Executioner of the Stormforged Conqueror')
        ->and($weapon->slotId)->toBe(3)
        ->and($set->itemsIn(0)[0]->name)->toBe('Warplate of the Eminent Champion')
        ->and($set->itemsIn(5)[0]->name)->toBe('Visor of the Stormforged Conqueror')
        ->and($set->itemsIn(9)[0]->iid)->toBe(1691428761);
});

it('keeps every orb sharing the orb slot', function () {
    $set = $this->parser->parse(gameFixture('equipment_page.html'));

    expect($set->itemsIn(8))->toHaveCount(3)
        ->and(collect($set->itemsIn(8))->pluck('iid')->all())
        ->toBe([1799021755, 1799744459, 1802708906]);
});

it('treats a slot with no item as empty', function () {
    $geared = $this->parser->parse(gameFixture('equipment_page.html'));
    $bootsOff = $this->parser->parse(gameFixture('equipment_page_empty_slot.html'));

    expect($geared->isEmpty(2))->toBeFalse()
        ->and($bootsOff->isEmpty(2))->toBeTrue()
        ->and($bootsOff->itemsIn(2))->toBe([])
        ->and($bootsOff->all())->toHaveCount(19);
});

it('reports a naked character as wearing nothing', function () {
    $set = $this->parser->parse(gameFixture('equipment_page_naked.html'));

    expect($set->all())->toBe([])
        ->and($set->slots)->toBe([])
        ->and($set->isEmpty(3))->toBeTrue();
});

it('maps doll slots onto the tooltip slot names', function () {
    $set = $this->parser->parse(gameFixture('equipment_page.html'));

    expect($set->itemsInSlotNamed('Weapon')[0]->iid)->toBe(1725164029)
        ->and($set->itemsInSlotNamed('Boots')[0]->name)->toBe('Plaguespark Sollerets')
        // Orbs and crests are outside the wearable-gear map.
        ->and($set->itemsInSlotNamed('Other'))->toBe([])
        ->and(EquipmentSet::SLOT_NAMES[3])->toBe('Weapon');
});
