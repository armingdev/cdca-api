<?php

use App\Models\Quest;
use App\Models\QuestList;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('creates, lists, and shows quest lists scoped to the user', function () {
    $this->postJson('/api/v1/quest-lists', ['name' => 'Armins List'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Armins List');

    QuestList::factory()->for(User::factory())->create(); // someone else's

    $this->getJson('/api/v1/quest-lists')->assertOk()->assertJsonCount(1, 'data');
});

it('adds and removes catalog quests, keeping positions contiguous', function () {
    $list = QuestList::factory()->for($this->user)->create();
    $street = Quest::factory()->create(['name' => 'Street Crawler', 'giver' => 'Stella']);
    $church = Quest::factory()->create(['name' => 'Cleansing the Church', 'giver' => 'Stella']);

    $this->postJson("/api/v1/quest-lists/{$list->id}/items", ['quest_id' => $street->id, 'label' => 'First!'])
        ->assertOk()
        ->assertJsonPath('data.items.0.quest_id', $street->id)
        ->assertJsonPath('data.items.0.quest.name', 'Street Crawler')
        ->assertJsonPath('data.items.0.quest.giver', 'Stella')
        ->assertJsonPath('data.items.0.display_name', 'First!');

    $this->postJson("/api/v1/quest-lists/{$list->id}/items", ['quest_id' => $church->id])
        ->assertOk()
        ->assertJsonCount(2, 'data.items');

    $this->deleteJson("/api/v1/quest-lists/{$list->id}/items/1")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.quest_id', $church->id)
        ->assertJsonPath('data.items.0.position', 1);
});

it('rejects quest ids that are not in the catalog', function () {
    $list = QuestList::factory()->for($this->user)->create();

    $this->postJson("/api/v1/quest-lists/{$list->id}/items", ['quest_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quest_id');
});

it('forbids touching another user\'s quest list', function () {
    $other = QuestList::factory()->for(User::factory())->create();
    $quest = Quest::factory()->create();

    $this->getJson("/api/v1/quest-lists/{$other->id}")->assertForbidden();
    $this->postJson("/api/v1/quest-lists/{$other->id}/items", ['quest_id' => $quest->id])->assertForbidden();
});
