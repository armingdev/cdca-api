<?php

use App\Models\Quest;
use App\Models\QuestList;

it('appends quests in order and exposes a display name', function () {
    $list = QuestList::create(['name' => 'Armins List']);
    $street = Quest::factory()->create(['name' => 'Street Crawler']);
    $church = Quest::factory()->create(['name' => 'Cleansing the Church']);

    $a = $list->addQuest($street->id, 'My Label');
    $b = $list->addQuest($church->id);

    expect($a->position)->toBe(1)
        ->and($b->position)->toBe(2)
        ->and($a->displayName())->toBe('My Label')
        ->and($b->displayName())->toBe('Cleansing the Church')
        ->and($list->items()->pluck('quest_id')->all())->toBe([$street->id, $church->id]);
});

it('removes an item and closes the position gap', function () {
    $list = QuestList::create(['name' => 'Armins List']);
    $quests = Quest::factory()->count(3)->create();

    $list->addQuest($quests[0]->id);
    $list->addQuest($quests[1]->id);
    $list->addQuest($quests[2]->id);

    expect($list->removePosition(2))->toBeTrue()
        ->and($list->items()->pluck('position')->all())->toBe([1, 2])
        ->and($list->items()->pluck('quest_id')->all())->toBe([$quests[0]->id, $quests[2]->id]);
});

it('reports failure removing a non-existent position', function () {
    $list = QuestList::create(['name' => 'Empty']);

    expect($list->removePosition(5))->toBeFalse();
});
