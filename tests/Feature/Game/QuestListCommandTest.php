<?php

use App\Models\QuestList;

it('creates a quest list', function () {
    $this->artisan('outwar:questlist', ['action' => 'create', 'name' => 'Armins List'])
        ->assertSuccessful()
        ->expectsOutputToContain("Created quest list 'Armins List'");

    expect(QuestList::where('name', 'Armins List')->exists())->toBeTrue();
});

it('rejects creating a duplicate list', function () {
    QuestList::create(['name' => 'Armins List']);

    $this->artisan('outwar:questlist', ['action' => 'create', 'name' => 'Armins List'])
        ->assertFailed()
        ->expectsOutputToContain('already exists');
});

it('adds a quest to a list', function () {
    QuestList::create(['name' => 'Armins List']);

    $this->artisan('outwar:questlist', [
        'action' => 'add',
        'name' => 'Armins List',
        '--quest' => 742,
        '--npc' => 'Stella',
        '--label' => 'Street Crawler',
    ])->assertSuccessful()->expectsOutputToContain('Added Street Crawler at position 1');

    $item = QuestList::where('name', 'Armins List')->first()->items()->first();

    expect($item->quest_id)->toBe(742)
        ->and($item->npc_name)->toBe('Stella')
        ->and($item->position)->toBe(1);
});

it('requires --quest and --npc when adding', function () {
    QuestList::create(['name' => 'Armins List']);

    $this->artisan('outwar:questlist', ['action' => 'add', 'name' => 'Armins List', '--quest' => 742])
        ->assertFailed()
        ->expectsOutputToContain('needs --quest');
});

it('shows a list with its quests', function () {
    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest(742, 'Stella', 'Street Crawler');

    $this->artisan('outwar:questlist', ['action' => 'show', 'name' => 'Armins List'])
        ->assertSuccessful()
        ->expectsOutputToContain('Street Crawler');
});
