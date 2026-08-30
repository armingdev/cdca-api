<?php

use App\Game\Engine\RunEventRecorder;
use App\Game\Enums\RunEventType;
use App\Models\Character;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\RunParticipant;
use App\Models\User;

beforeEach(function () {
    $user = User::factory()->create();
    $rga = Rga::factory()->for($user)->withSession()->create();
    $this->character = Character::factory()->for($rga)->create();
    $this->participant = RunParticipant::factory()
        ->for(Run::factory()->for($user))
        ->for($this->character)
        ->create();
});

it('records a decision to the journal and the live line', function () {
    (new RunEventRecorder($this->participant))->record(
        RunEventType::SkillSkipped,
        'Empower is on cooldown.',
        ['skill_id' => 42, 'reason' => 'cooldown'],
        RunEvent::LEVEL_WARNING,
    );

    $event = RunEvent::sole();

    expect($event->type)->toBe(RunEventType::SkillSkipped)
        ->and($event->level)->toBe(RunEvent::LEVEL_WARNING)
        ->and($event->context)->toMatchArray(['skill_id' => 42, 'reason' => 'cooldown'])
        ->and($event->run_id)->toBe($this->participant->run_id)
        ->and($event->character_id)->toBe($this->character->id)
        ->and($event->created_at)->not->toBeNull()
        ->and($this->participant->fresh()->last_activity)->toBe('Empower is on cooldown.');
});

it('keeps loop chatter out of the journal', function () {
    $recorder = new RunEventRecorder($this->participant);
    $recorder->activity('Walking to room 12.');
    $recorder->logger()('Walking to room 13.');

    expect(RunEvent::count())->toBe(0)
        ->and($this->participant->fresh()->last_activity)->toBe('Walking to room 13.');
});

it('truncates an overlong message to fit both columns', function () {
    (new RunEventRecorder($this->participant))->record(RunEventType::Info, str_repeat('a', 900));

    expect(mb_strlen(RunEvent::sole()->message))->toBeLessThanOrEqual(500)
        ->and(mb_strlen($this->participant->fresh()->last_activity))->toBeLessThanOrEqual(255);
});

it('prunes events past the retention window', function () {
    $recorder = new RunEventRecorder($this->participant);

    $this->travelTo(now()->subDays(45), fn () => $recorder->record(RunEventType::Info, 'Ancient.'));
    $recorder->record(RunEventType::Info, 'Fresh.');

    $this->artisan('outwar:run-events-prune')->assertSuccessful();

    expect(RunEvent::pluck('message')->all())->toBe(['Fresh.']);
});
