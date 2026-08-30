<?php

use App\Game\Enums\QuestProgressStatus;
use App\Models\Character;
use App\Models\CharacterQuestProgress;
use App\Models\Quest;
use App\Models\Rga;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->rga = Rga::factory()->for($this->user)->withSession()->create();
    $this->character = Character::factory()->for($this->rga)->create();
});

it('lists a character\'s recorded quest progress', function () {
    $quest = Quest::factory()->create(['game_quest_id' => 742, 'name' => 'Street Crawler', 'giver' => 'Stella']);
    CharacterQuestProgress::factory()->for($this->character)->for($quest)->create();

    $this->getJson("/api/v1/characters/{$this->character->id}/quest-progress")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'completed')
        ->assertJsonPath('data.0.quest.name', 'Street Crawler');
});

it('filters the listing by status', function () {
    CharacterQuestProgress::factory()->for($this->character)->for(Quest::factory())->create();
    CharacterQuestProgress::factory()->for($this->character)->for(Quest::factory())->unavailable()->create();

    $this->getJson("/api/v1/characters/{$this->character->id}/quest-progress?status=unavailable")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'unavailable');
});

it('rejects an unknown status and an out-of-range per_page', function () {
    $this->getJson("/api/v1/characters/{$this->character->id}/quest-progress?status=nonsense")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $this->getJson("/api/v1/characters/{$this->character->id}/quest-progress?per_page=500")
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

it('clears a character\'s quest progress', function () {
    // A distinct quest each: the table is unique per (character, quest).
    Quest::factory()->count(3)->create()->each(
        fn (Quest $quest) => CharacterQuestProgress::factory()->for($this->character)->for($quest)->create()
    );

    $this->deleteJson("/api/v1/characters/{$this->character->id}/quest-progress")
        ->assertOk()
        ->assertJsonPath('deleted', 3);

    expect(CharacterQuestProgress::count())->toBe(0);
});

it('clears only the requested status, so a completion survives forgetting a guess', function () {
    CharacterQuestProgress::factory()->for($this->character)->for(Quest::factory())->create();
    CharacterQuestProgress::factory()->for($this->character)->for(Quest::factory())->unavailable()->create();

    $this->deleteJson("/api/v1/characters/{$this->character->id}/quest-progress?status=unavailable")
        ->assertOk()
        ->assertJsonPath('deleted', 1);

    expect(CharacterQuestProgress::sole()->status)->toBe(QuestProgressStatus::Completed);
});

it('clears every character on the account at once', function () {
    $sibling = Character::factory()->for($this->rga)->create();
    CharacterQuestProgress::factory()->for($this->character)->for(Quest::factory())->create();
    CharacterQuestProgress::factory()->for($sibling)->for(Quest::factory())->create();

    // A character on someone else's account must be untouched.
    $stranger = Character::factory()->for(Rga::factory()->for(User::factory())->withSession())->create();
    CharacterQuestProgress::factory()->for($stranger)->for(Quest::factory())->create();

    $this->deleteJson("/api/v1/rgas/{$this->rga->id}/quest-progress")
        ->assertOk()
        ->assertJsonPath('deleted', 2);

    expect(CharacterQuestProgress::count())->toBe(1)
        ->and(CharacterQuestProgress::sole()->character_id)->toBe($stranger->id);
});

it('does not expose or clear another user\'s quest progress', function () {
    $stranger = Character::factory()->for(Rga::factory()->for(User::factory())->withSession())->create();

    $this->getJson("/api/v1/characters/{$stranger->id}/quest-progress")->assertForbidden();
    $this->deleteJson("/api/v1/characters/{$stranger->id}/quest-progress")->assertForbidden();
});
