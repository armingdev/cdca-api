<?php

use App\Game\Engine\RunEventRecorder;
use App\Game\Enums\RunEventType;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\RunParticipant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->rga = Rga::factory()->for($this->user)->withSession()->create();
    $this->character = Character::factory()->for($this->rga)->create();
    $this->run = Run::factory()->for($this->user)->create();
    $this->participant = RunParticipant::factory()
        ->for($this->run)
        ->for($this->character)
        ->create();
});

it('returns the run log newest first', function () {
    $recorder = new RunEventRecorder($this->participant);
    $recorder->record(RunEventType::QuestSkipped, 'Skipped quest 1.');
    $recorder->record(RunEventType::SkillCast, 'Cast Empower.');

    $response = $this->getJson("/api/v1/runs/{$this->run->id}/events");

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.message', 'Cast Empower.')
        ->assertJsonPath('data.0.type', 'skill_cast')
        ->assertJsonPath('data.0.character', $this->character->name)
        ->assertJsonPath('data.1.message', 'Skipped quest 1.');
});

it('filters by type, level, character and after_id', function () {
    $recorder = new RunEventRecorder($this->participant);
    $recorder->record(RunEventType::QuestSkipped, 'Skipped.', ['reason' => 'no_giver'], RunEvent::LEVEL_WARNING);
    $recorder->record(RunEventType::SkillCast, 'Cast.');

    $this->getJson("/api/v1/runs/{$this->run->id}/events?type=quest_skipped")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.context.reason', 'no_giver');

    $this->getJson("/api/v1/runs/{$this->run->id}/events?level=warning")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/v1/runs/{$this->run->id}/events?character_id={$this->character->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $firstId = RunEvent::orderBy('id')->value('id');
    $this->getJson("/api/v1/runs/{$this->run->id}/events?after_id={$firstId}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.message', 'Cast.');
});

it('rejects an out-of-range per_page and an unknown type', function () {
    $this->getJson("/api/v1/runs/{$this->run->id}/events?per_page=500")
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');

    $this->getJson("/api/v1/runs/{$this->run->id}/events?type=nonsense")
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('does not expose another user\'s run log', function () {
    $otherRun = Run::factory()->for(User::factory())->create();

    $this->getJson("/api/v1/runs/{$otherRun->id}/events")->assertForbidden();
});

it('scopes events to the requested run', function () {
    (new RunEventRecorder($this->participant))->record(RunEventType::Info, 'Mine.');

    $otherRun = Run::factory()->for($this->user)->create();
    $otherParticipant = RunParticipant::factory()
        ->for($otherRun)
        ->for(Character::factory()->for($this->rga))
        ->create();
    (new RunEventRecorder($otherParticipant))->record(RunEventType::Info, 'Theirs.');

    $this->getJson("/api/v1/runs/{$this->run->id}/events")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.message', 'Mine.');
});
