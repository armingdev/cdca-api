<?php

use App\Game\Enums\RunStatus;
use App\Jobs\RunMobJob;
use App\Jobs\RunPvpJob;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Quest;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->rga = Rga::factory()->for($this->user)->withSession()->create();
});

it('starts a mob run for owned characters and queues a job per character', function () {
    Queue::fake();
    $characters = Character::factory()->for($this->rga)->count(2)->create();

    $response = $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => $characters->pluck('id')->all(),
        'mobs' => ['Kix Harvester'],
        'max_kills' => 5,
        'cast_on_start' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.mode', 'mob')
        ->assertJsonPath('data.cast_on_start', true)
        ->assertJsonCount(2, 'data.participants');

    $run = Run::first();
    expect($run->user_id)->toBe($this->user->id)
        ->and($run->config['mob_names'])->toBe(['Kix Harvester']);

    Queue::assertPushed(RunMobJob::class, 2);
});

it('starts a pvp run', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'pvp-attack-list',
        'characters' => [$character->id],
        'targets' => ['OFFENSIVE'],
        'attacks_per_target' => 3,
    ])->assertCreated()->assertJsonPath('data.config.attacks_per_target', 3);

    Queue::assertPushed(RunPvpJob::class, 1);
});

it('accepts and stores the mob pass options', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
        'run_count' => 3,
        'attack_interval_seconds' => 300,
    ])->assertCreated()
        ->assertJsonPath('data.config.run_count', 3)
        ->assertJsonPath('data.config.attack_interval_seconds', 300);

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
        'attack_interval_seconds' => 5,
    ])->assertStatus(422)->assertJsonValidationErrorFor('attack_interval_seconds');
});

it('defaults a mob run to an endless farm', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
    ])->assertCreated()->assertJsonPath('data.config.run_count', 0);
});

it('stores the smart flag on the run config', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'quest',
        'characters' => [$character->id],
        'npc' => 'Stella',
        'quest_id' => 742,
        'smart' => true,
    ])->assertCreated()->assertJsonPath('data.config.smart', true);

    expect(Run::first()->config['smart'])->toBeTrue();
});

it('defaults the smart flag to off', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
    ])->assertCreated()->assertJsonPath('data.config.smart', false);
});

it('stores a custom respawn wait on quest runs and defaults it otherwise', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();
    $other = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'quest',
        'characters' => [$character->id],
        'npc' => 'Stella',
        'quest_id' => 742,
        'respawn_wait_seconds' => 600,
    ])->assertCreated()->assertJsonPath('data.config.respawn_wait_seconds', 600);

    $this->postJson('/api/v1/runs', [
        'mode' => 'quest',
        'characters' => [$other->id],
        'npc' => 'Stella',
        'quest_id' => 742,
    ])->assertCreated()->assertJsonPath('data.config.respawn_wait_seconds', 60);
});

it('rejects a respawn wait below the one-minute floor', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'quest',
        'characters' => [$character->id],
        'npc' => 'Stella',
        'quest_id' => 742,
        'respawn_wait_seconds' => 5,
    ])->assertStatus(422)->assertJsonValidationErrorFor('respawn_wait_seconds');
});

it('rejects a run for a character already enrolled in an active run', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();
    RunParticipant::factory()
        ->for(Run::factory()->for($this->user)->state(['status' => RunStatus::Running]))
        ->for($character)
        ->create(['status' => RunStatus::Running]);

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
    ])->assertStatus(422)->assertJsonValidationErrorFor('characters');

    Queue::assertNothingPushed();
});

it('rejects a run that uses characters the user does not own', function () {
    Queue::fake();
    $foreign = Character::factory()->for(Rga::factory()->for(User::factory()))->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$foreign->id],
        'mobs' => ['Kix Harvester'],
    ])->assertStatus(422)->assertJsonValidationErrorFor('characters');

    Queue::assertNothingPushed();
});

it('validates mode-specific fields', function () {
    Queue::fake();
    $character = Character::factory()->for($this->rga)->create();

    // quest mode without npc/quest_id
    $this->postJson('/api/v1/runs', ['mode' => 'quest', 'characters' => [$character->id]])
        ->assertStatus(422);
});

it('shows a run and stops it gracefully', function () {
    $run = Run::factory()->for($this->user)->state(['status' => RunStatus::Running])->create();
    $participant = RunParticipant::factory()->for($run)->for(Character::factory()->for($this->rga))->create(['status' => RunStatus::Running]);

    $this->getJson("/api/v1/runs/{$run->id}")->assertOk()->assertJsonPath('data.id', $run->id);

    $this->postJson("/api/v1/runs/{$run->id}/stop")
        ->assertOk()
        ->assertJsonPath('data.status', 'stopping');

    expect($participant->fresh()->status)->toBe(RunStatus::Stopping);
});

it('stops a run that is parked waiting for a respawn or a cooldown', function () {
    Queue::fake();

    $run = Run::factory()->for($this->user)->state(['status' => RunStatus::Waiting])->create();
    $waiting = RunParticipant::factory()->for($run)->for(Character::factory()->for($this->rga))
        ->create(['status' => RunStatus::Waiting, 'resume_at' => now()->addHour()]);

    $this->postJson("/api/v1/runs/{$run->id}/stop")
        ->assertOk()
        ->assertJsonPath('data.status', 'stopped');

    expect($waiting->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($waiting->fresh()->resume_at)->toBeNull()
        ->and($waiting->fresh()->finished_at)->not->toBeNull();

    // The resume scheduler must not pick it back up.
    $this->travelTo(now()->addHours(2));
    $this->artisan('outwar:runs-resume-due')->assertSuccessful();

    expect($waiting->fresh()->status)->toBe(RunStatus::Stopped);
    Queue::assertNothingPushed();
});

it('pauses a running run and resumes it with a fresh dispatch', function () {
    Queue::fake();

    $run = Run::factory()->for($this->user)->state(['status' => RunStatus::Running])->create();
    $waiting = RunParticipant::factory()->for($run)->for(Character::factory()->for($this->rga))
        ->create(['status' => RunStatus::Waiting, 'resume_at' => now()->addHour()]);

    $this->postJson("/api/v1/runs/{$run->id}/pause")
        ->assertOk()
        ->assertJsonPath('data.status', 'paused');

    expect($waiting->fresh()->status)->toBe(RunStatus::Paused)
        ->and($waiting->fresh()->resume_at)->toBeNull();

    $this->postJson("/api/v1/runs/{$run->id}/resume")
        ->assertOk()
        ->assertJsonPath('data.status', 'running');

    expect($waiting->fresh()->status)->toBe(RunStatus::Pending)
        ->and($waiting->fresh()->dispatch_token)->not->toBeNull();

    Queue::assertPushed(RunMobJob::class, 1);
});

it('rejects pausing a finished run and resuming a non-paused run', function () {
    $finished = Run::factory()->for($this->user)->state(['status' => RunStatus::Completed])->create();
    $running = Run::factory()->for($this->user)->state(['status' => RunStatus::Running])->create();

    $this->postJson("/api/v1/runs/{$finished->id}/pause")->assertStatus(422);
    $this->postJson("/api/v1/runs/{$running->id}/resume")->assertStatus(422);
});

it('stops parked participants immediately when a paused run is stopped', function () {
    $run = Run::factory()->for($this->user)->state(['status' => RunStatus::Paused])->create();
    $paused = RunParticipant::factory()->for($run)->for(Character::factory()->for($this->rga))
        ->create(['status' => RunStatus::Paused]);

    $this->postJson("/api/v1/runs/{$run->id}/stop")
        ->assertOk()
        ->assertJsonPath('data.status', 'stopped');

    expect($paused->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($paused->fresh()->finished_at)->not->toBeNull();
});

it('deletes only finished runs', function () {
    $finished = Run::factory()->for($this->user)->state(['status' => RunStatus::Completed])->create();
    RunParticipant::factory()->for($finished)->for(Character::factory()->for($this->rga))->create(['status' => RunStatus::Completed]);

    $this->deleteJson("/api/v1/runs/{$finished->id}")->assertOk();
    expect(Run::find($finished->id))->toBeNull()
        ->and(RunParticipant::where('run_id', $finished->id)->count())->toBe(0);

    $live = Run::factory()->for($this->user)->state(['status' => RunStatus::Running])->create();
    $this->deleteJson("/api/v1/runs/{$live->id}")->assertStatus(422);

    $foreign = Run::factory()->for(User::factory())->state(['status' => RunStatus::Completed])->create();
    $this->deleteJson("/api/v1/runs/{$foreign->id}")->assertForbidden();
});

it('lists only the user\'s runs and forbids others', function () {
    Run::factory()->for($this->user)->create();
    $other = Run::factory()->for(User::factory())->create();

    $this->getJson('/api/v1/runs')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/runs/{$other->id}")->assertForbidden();
});

it('paginates the run history and caps the page size', function () {
    Run::factory()->for($this->user)->count(3)->create();

    $this->getJson('/api/v1/runs?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2);

    $this->getJson('/api/v1/runs?per_page=5000')
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('per_page');
});

/**
 * The fleet-wide skill picker: one selection, ten characters, one request.
 * It writes the same per-character rows the Skills page does, so each of them
 * also keeps that set as its own default afterwards.
 */
function seedTwoSkills(): void
{
    Skill::create(['id' => 4, 'name' => 'Stealth', 'school' => 'class', 'rage_cost' => 10, 'cooldown_minutes' => 60, 'duration_minutes' => 60]);
    Skill::create(['id' => 9, 'name' => 'Boost', 'school' => 'ferocity', 'rage_cost' => 10, 'cooldown_minutes' => 120, 'duration_minutes' => 60]);
}

function castOnStartIdsFor(Character $character): array
{
    return CharacterSkill::where('character_id', $character->id)
        ->where('cast_on_start', true)
        ->orderBy('skill_id')
        ->pluck('skill_id')
        ->all();
}

it('applies one skill selection to every character in the run', function () {
    Queue::fake();
    seedTwoSkills();
    $characters = Character::factory()->for($this->rga)->count(3)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => $characters->pluck('id')->all(),
        'mobs' => ['Kix Harvester'],
        // Duplicated on purpose: the stored selection is deduplicated.
        'skill_ids' => [4, 9, 4],
    ])
        ->assertCreated()
        ->assertJsonPath('data.skill_ids', [4, 9])
        // A selection nothing casts is pointless, so the flag comes on by itself.
        ->assertJsonPath('data.cast_on_start', true);

    foreach ($characters as $character) {
        expect(castOnStartIdsFor($character))->toBe([4, 9]);
    }
});

it('leaves every character\'s own selection alone when the run sends no skill ids', function () {
    Queue::fake();
    seedTwoSkills();
    $character = Character::factory()->for($this->rga)->create();
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => 4, 'cast_on_start' => true]);

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.skill_ids', null)
        ->assertJsonPath('data.cast_on_start', false);

    expect(castOnStartIdsFor($character))->toBe([4]);
});

it('clears every selected character\'s set when the run sends an empty skill list', function () {
    Queue::fake();
    seedTwoSkills();
    $character = Character::factory()->for($this->rga)->create();
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => 4, 'cast_on_start' => true]);

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
        'skill_ids' => [],
    ])
        ->assertCreated()
        ->assertJsonPath('data.skill_ids', [])
        // Nothing to cast, so an empty selection must not arm the flag.
        ->assertJsonPath('data.cast_on_start', false);

    expect(castOnStartIdsFor($character))->toBe([]);
});

it('rejects a fleet skill selection the run would never cast', function () {
    Queue::fake();
    seedTwoSkills();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
        'skill_ids' => [4],
        'cast_on_start' => false,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cast_on_start');

    expect(Run::count())->toBe(0);
});

it('rejects a fleet skill selection naming a skill that does not exist', function () {
    Queue::fake();
    seedTwoSkills();
    $character = Character::factory()->for($this->rga)->create();

    $this->postJson('/api/v1/runs', [
        'mode' => 'mob',
        'characters' => [$character->id],
        'mobs' => ['Kix Harvester'],
        'skill_ids' => [4, 999999],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('skill_ids');

    expect(castOnStartIdsFor($character))->toBe([]);
});

describe('run names', function () {
    it('stores the name a run is started with and returns it', function () {
        Queue::fake();
        $character = Character::factory()->for($this->rga)->create();

        $this->postJson('/api/v1/runs', [
            'mode' => 'mob',
            'name' => 'amdir harvester',
            'characters' => [$character->id],
            'mobs' => ['Amdir Harvester'],
        ])->assertCreated()->assertJsonPath('data.name', 'amdir harvester');

        expect(Run::sole()->name)->toBe('amdir harvester');
    });

    it('leaves a run unnamed when no name is given', function () {
        Queue::fake();
        $character = Character::factory()->for($this->rga)->create();

        $this->postJson('/api/v1/runs', [
            'mode' => 'mob',
            'characters' => [$character->id],
            'mobs' => ['Amdir Harvester'],
        ])->assertCreated()->assertJsonPath('data.name', null);
    });

    it('renames a run, and clears the name when sent null', function () {
        $run = Run::factory()->for($this->user)->create(['name' => 'tincture mobs']);

        $this->patchJson("/api/v1/runs/{$run->id}", ['name' => 'sub85 veldara'])
            ->assertOk()
            ->assertJsonPath('data.name', 'sub85 veldara');

        $this->patchJson("/api/v1/runs/{$run->id}", ['name' => null])->assertOk();

        expect($run->fresh()->name)->toBeNull();
    });

    it('returns 403 when renaming another user\'s run', function () {
        $run = Run::factory()->for(User::factory())->create(['name' => 'theirs']);

        $this->patchJson("/api/v1/runs/{$run->id}", ['name' => 'mine now'])->assertForbidden();

        expect($run->fresh()->name)->toBe('theirs');
    });

    it('returns 422 for a name longer than 80 characters or a rename without one', function (array $payload) {
        $run = Run::factory()->for($this->user)->create(['name' => 'kept']);

        $this->patchJson("/api/v1/runs/{$run->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        expect($run->fresh()->name)->toBe('kept');
    })->with([
        'too long' => [['name' => str_repeat('x', 81)]],
        'missing' => [[]],
    ]);
});

describe('single-quest runs picked from the catalog', function () {
    it('takes the giver and the game quest id from the picked quest', function () {
        Queue::fake();
        $character = Character::factory()->for($this->rga)->create();
        $quest = Quest::factory()->create(['game_quest_id' => 1128, 'giver' => 'Tyson']);

        $this->postJson('/api/v1/runs', [
            'mode' => 'quest',
            'characters' => [$character->id],
            'catalog_quest_id' => $quest->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.config.npc_name', 'Tyson')
            ->assertJsonPath('data.config.quest_id', 1128);
    });

    it('returns 422 when the picked quest is not in the catalog', function () {
        $character = Character::factory()->for($this->rga)->create();

        $this->postJson('/api/v1/runs', [
            'mode' => 'quest',
            'characters' => [$character->id],
            'catalog_quest_id' => 999_999,
        ])->assertUnprocessable()->assertJsonValidationErrors('catalog_quest_id');

        expect(Run::count())->toBe(0);
    });

    it('returns 422 when the catalog does not know the quest\'s giver', function () {
        $character = Character::factory()->for($this->rga)->create();
        $quest = Quest::factory()->create(['name' => 'Orphaned Errand', 'giver' => null]);

        $this->postJson('/api/v1/runs', [
            'mode' => 'quest',
            'characters' => [$character->id],
            'catalog_quest_id' => $quest->id,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.catalog_quest_id.0', 'The catalog does not know who gives Orphaned Errand yet.');

        expect(Run::count())->toBe(0);
    });

    it('still requires the giver and quest id when no catalog quest is picked', function () {
        $character = Character::factory()->for($this->rga)->create();

        $this->postJson('/api/v1/runs', ['mode' => 'quest', 'characters' => [$character->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['npc', 'quest_id']);
    });
});

describe('rage reserve options', function () {
    it('stores the events a run saves rage for and the hours before them', function () {
        Queue::fake();
        $character = Character::factory()->for($this->rga)->create();

        $this->postJson('/api/v1/runs', [
            'mode' => 'mob',
            'characters' => [$character->id],
            'mobs' => ['Amdir Harvester'],
            'reserve_rage_for' => ['pvp-brawl', 'faction-brawl'],
            'reserve_rage_hours' => 8,
        ])
            ->assertCreated()
            ->assertJsonPath('data.reserve_rage_for', ['pvp-brawl', 'faction-brawl'])
            ->assertJsonPath('data.reserve_rage_hours', 8);
    });

    it('reserves nothing by default', function () {
        Queue::fake();
        $character = Character::factory()->for($this->rga)->create();

        $this->postJson('/api/v1/runs', ['mode' => 'mob', 'characters' => [$character->id], 'mobs' => ['Amdir Harvester']])
            ->assertCreated()
            ->assertJsonPath('data.reserve_rage_for', [])
            ->assertJsonPath('data.reserve_rage_hours', 12);
    });

    it('returns 422 for an event it cannot schedule around or an out-of-range lead time', function (array $payload, string $field) {
        $character = Character::factory()->for($this->rga)->create();

        $this->postJson('/api/v1/runs', ['mode' => 'mob', 'characters' => [$character->id], 'mobs' => ['Amdir Harvester'], ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'gladiator is not supported yet' => [['reserve_rage_for' => ['gladiator']], 'reserve_rage_for.0'],
        'zero hours' => [['reserve_rage_for' => ['pvp-brawl'], 'reserve_rage_hours' => 0], 'reserve_rage_hours'],
        'more than three days' => [['reserve_rage_for' => ['pvp-brawl'], 'reserve_rage_hours' => 73], 'reserve_rage_hours'],
    ]);
});
