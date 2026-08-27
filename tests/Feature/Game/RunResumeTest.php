<?php

use App\Game\Auth\LoginService;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Jobs\RunMobJob;
use App\Jobs\RunQuestJob;
use App\Jobs\RunQuestListJob;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Mob;
use App\Models\QuestList;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use App\Models\Skill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    seedCombatWorld();
});

function seedCircumspect(): Skill
{
    return Skill::create([
        'id' => Skill::CIRCUMSPECT_ID,
        'name' => 'Circumspect',
        'school' => 'ferocity',
        'rage_cost' => 20,
        'cooldown_minutes' => 720,
        'duration_minutes' => 60,
    ]);
}

it('re-dispatches only due participants of self-propelling runs', function () {
    Queue::fake();

    $character = fn () => Character::factory()->for(Rga::factory()->withSession());

    $eligible = Run::factory()->state(['status' => RunStatus::Waiting])->create();
    $due = RunParticipant::factory()->for($eligible)->for($character())
        ->create(['status' => RunStatus::Waiting, 'resume_at' => now()->subMinute()]);
    $future = RunParticipant::factory()->for($eligible)->for($character())
        ->create(['status' => RunStatus::Waiting, 'resume_at' => now()->addHour()]);

    $stoppedRun = Run::factory()->state(['status' => RunStatus::Stopped])->create();
    $orphan = RunParticipant::factory()->for($stoppedRun)->for($character())
        ->create(['status' => RunStatus::Waiting, 'resume_at' => now()->subMinute()]);

    $this->artisan('outwar:runs-resume-due')
        ->assertSuccessful()
        ->expectsOutputToContain("Resumed participant #{$due->id}");

    expect($due->fresh()->status)->toBe(RunStatus::Pending)
        ->and($due->fresh()->resume_at)->toBeNull()
        ->and($due->fresh()->dispatch_token)->not->toBeNull()
        ->and($future->fresh()->status)->toBe(RunStatus::Waiting)
        ->and($orphan->fresh()->status)->toBe(RunStatus::Waiting)
        ->and($eligible->fresh()->status)->toBe(RunStatus::Running);

    Queue::assertPushed(RunMobJob::class, 1);
});

it('cycles a circ-gated mob run: wait out the cooldown, recast, and finish', function () {
    Queue::fake();
    fakeCombatWorld();
    seedCircumspect();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    // Cast 70m ago: buff expired, cooldown has 650m left.
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => Skill::CIRCUMSPECT_ID, 'last_cast_at' => now()->subMinutes(70), 'trained_level' => 1]);

    $run = Run::factory()->state([
        'config' => ['mob_names' => ['Kix Harvester'], 'max_kills' => 1],
        'require_circumspect' => true,
        'status' => RunStatus::Running,
    ])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Waiting)
        ->and($run->fresh()->status)->toBe(RunStatus::Waiting);

    // The scheduler fires once the cooldown (plus buffer) has elapsed.
    $this->travelTo($participant->fresh()->resume_at->addMinute());

    $this->artisan('outwar:runs-resume-due')->assertSuccessful();

    expect($participant->fresh()->status)->toBe(RunStatus::Pending)
        ->and($run->fresh()->status)->toBe(RunStatus::Running);
    Queue::assertPushed(RunMobJob::class, 1);

    // Simulate the re-dispatched worker: Circumspect is castable again.
    makeRunJob($participant->fresh())->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->wins)->toBe(1)
        ->and(CharacterSkill::where('character_id', $character->id)->where('skill_id', Skill::CIRCUMSPECT_ID)->value('last_cast_at'))
        ->toBeBetween(now()->subMinute(), now())
        ->and($run->fresh()->status)->toBe(RunStatus::Completed);
});

it('waits on the circ recharge when rage runs out while the buff is active', function () {
    fakeCombatWorld(rage: 100);
    seedCircumspect();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    // Cast 5m ago: buff active for another ~55m, cooldown has ~715m left.
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => Skill::CIRCUMSPECT_ID, 'last_cast_at' => now()->subMinutes(5), 'trained_level' => 1]);

    $run = Run::factory()->state([
        'config' => ['mob_names' => ['Kix Harvester'], 'stop_rage' => 2500],
        'require_circumspect' => true,
        'status' => RunStatus::Running,
    ])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->last_activity)->toContain('Waiting for Circumspect')
        ->and($participant->resume_at->diffInMinutes(now()->addMinutes(717), true))->toBeLessThan(3)
        ->and($run->fresh()->status)->toBe(RunStatus::Waiting);
});

it('cycles a circ-gated quest list and resumes it from the persisted position', function () {
    Queue::fake();
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    $setRage = fakeQuestWorld(rage: 100);
    seedCircumspect();

    $catalog = seedQuestCatalog();
    $list = QuestList::create(['name' => 'Circ List']);
    $list->addQuest($catalog[742]->id);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    CharacterSkill::create(['character_id' => $character->id, 'skill_id' => Skill::CIRCUMSPECT_ID, 'last_cast_at' => now()->subMinutes(5), 'trained_level' => 1]);

    $run = Run::factory()->state([
        'mode' => RunMode::QuestList,
        'config' => ['quest_list_id' => $list->id, 'stop_rage' => 2500],
        'require_circumspect' => true,
        'status' => RunStatus::Running,
    ])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();
    $firstPosition = $list->items->first()->position;

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->progress['position'])->toBe($firstPosition);

    // Next cycle: cooldown over, full rage — the list finishes from its position.
    $this->travelTo($participant->resume_at->addMinute());
    $setRage(50000);

    $this->artisan('outwar:runs-resume-due')->assertSuccessful();
    Queue::assertPushed(RunQuestListJob::class, 1);

    makeRunJob($participant->fresh())->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->last_activity)->toContain('Quest list complete')
        ->and($run->fresh()->status)->toBe(RunStatus::Completed);
});

it('does not restart waiting runs from the restart scheduler', function () {
    Queue::fake();

    $waiting = Run::factory()->restartEvery(60)->state([
        'status' => RunStatus::Waiting,
        'last_started_at' => now()->subMinutes(90),
    ])->create();
    RunParticipant::factory()->for($waiting)->create(['status' => RunStatus::Waiting, 'resume_at' => now()->addHours(10)]);

    $this->artisan('outwar:runs-restart-due')->assertSuccessful();

    expect($waiting->fresh()->status)->toBe(RunStatus::Waiting);
    Queue::assertNothingPushed();
});

it('parks a quest whose targets are all dead and finishes it after they respawn', function () {
    Queue::fake();
    // seedCombatWorld() already laid down rooms 1–2; only the quest mobs are missing.
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    // Quest 742 needs 5 Street Crawlers; only 2 are alive to start with.
    $setWorld = fakeQuestWorld(liveMobs: 2);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $run = Run::factory()->state([
        'mode' => RunMode::Quest,
        'config' => ['npc_name' => 'Stella', 'quest_id' => 742, 'stop_rage' => 2500],
        'status' => RunStatus::Running,
    ])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->last_activity)->toContain('waiting for respawn')
        ->and($participant->progress['respawn_waits'])->toBe(1)
        ->and($participant->resume_at)->not->toBeNull()
        ->and($run->fresh()->status)->toBe(RunStatus::Waiting);

    // The mobs come back; the scheduler re-drives the parked participant.
    $this->travelTo($participant->resume_at->addSecond());
    $setWorld(liveMobs: 99);

    $this->artisan('outwar:runs-resume-due')->assertSuccessful();
    Queue::assertPushed(RunQuestJob::class, 1);

    makeRunJob($participant->fresh())->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->last_activity)->toContain('Quest complete')
        ->and($participant->progress['respawn_waits'])->toBe(0)
        ->and($run->fresh()->status)->toBe(RunStatus::Completed);
});

it('cuts an endless farm short the moment the Circumspect buff lapses mid-pass', function () {
    seedCircumspect();

    // A world of endlessly live targets where each fight costs 20 seconds, so
    // the pass outlives the buff window and the run has to notice mid-flight
    // rather than at a pass boundary. Only fighting moves the clock — the
    // pre-run skill sync must still happen inside the buff.
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'somethingelse.php')) {
            Carbon::setTestNow(Carbon::now()->addSeconds(20));
        }

        if (str_contains($url, 'userstats.php')) {
            return Http::response(json_encode(['exp' => '1,000', 'rage' => '50,000', 'level' => '60', 'width' => 0]));
        }

        if (str_contains($url, 'skills_info.php')) {
            return Http::response(fakeSkillInfoHtml());
        }

        if (str_contains($url, 'cast_skills.php')) {
            return Http::response('Status: You just cast a skill');
        }

        if (str_contains($url, 'somethingelse.php')) {
            return Http::response('', 302, ['Location' => 'https://sigil.outwar.com/attack/555/']);
        }

        if (str_contains($url, 'attack/555')) {
            return Http::response('var battle_result = "Hero has gained 950 experience!";'
                .'var attacker_name = "Hero"; var defender_name = "Kix Harvester";');
        }

        if (str_contains($url, 'ajax_changeroomb.php')) {
            return Http::response(json_encode([
                'error' => '', 'curRoom' => '2', 'name' => 'Room 2',
                'north' => '0', 'east' => '0', 'south' => '0', 'west' => '1',
                'roomDetailsNew' => [[
                    'name' => 'Kix Harvester', 'level' => '60', 'rage' => '150', 'h' => 'hash',
                    'encid' => 'FRESH'.random_int(1, 9999), 'mobId' => '777', 'spawnId' => '1234',
                    'isDead' => false, 'type' => 0, 'canForm' => false, 'lastKilledBy' => null,
                ]],
                'doorsData' => null,
            ]));
        }

        return Http::response('<html>world</html>');
    });

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    // Cast 59m ago: the 60m buff has a minute left, so the gate lets the run
    // start, and the 720m cooldown still has ~11h to go.
    CharacterSkill::create([
        'character_id' => $character->id,
        'skill_id' => Skill::CIRCUMSPECT_ID,
        'last_cast_at' => now()->subMinutes(59),
        'trained_level' => 1,
    ]);

    $run = Run::factory()->state([
        // max_kills is only a backstop: the buff should end the pass long first.
        'config' => ['mob_names' => ['Kix Harvester'], 'stop_rage' => 2500, 'max_kills' => 50],
        'require_circumspect' => true,
        'status' => RunStatus::Running,
    ])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->last_activity)->toContain('Circumspect expired')
        ->and($participant->last_activity)->toContain('Waiting for Circumspect')
        ->and($participant->wins)->toBeGreaterThan(0)
        ->and($participant->wins)->toBeLessThan(50)
        ->and($participant->resume_at)->toBeGreaterThan(now()->addHours(10))
        ->and($run->fresh()->status)->toBe(RunStatus::Waiting);
});

it('ends a quest pass when Circumspect lapses and resumes it once recast', function () {
    Queue::fake();
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeQuestWorld();
    seedCircumspect();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    CharacterSkill::create([
        'character_id' => $character->id,
        'skill_id' => Skill::CIRCUMSPECT_ID,
        'last_cast_at' => now()->subMinutes(61),
        'trained_level' => 1,
    ]);

    $run = Run::factory()->state([
        'mode' => RunMode::Quest,
        'config' => ['npc_name' => 'Stella', 'quest_id' => 742, 'stop_rage' => 2500],
        'require_circumspect' => true,
        'status' => RunStatus::Running,
    ])->create();
    $participant = RunParticipant::factory()->for($run)->for($character)->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Waiting)
        ->and($participant->last_activity)->toContain('Waiting for Circumspect');

    // Cooldown over: the gate recasts the skill and the quest finishes.
    $this->travelTo($participant->resume_at->addMinute());

    $this->artisan('outwar:runs-resume-due')->assertSuccessful();
    Queue::assertPushed(RunQuestJob::class, 1);

    makeRunJob($participant->fresh())->handle(app(LoginService::class));

    $participant->refresh();

    expect($participant->status)->toBe(RunStatus::Completed)
        ->and($participant->last_activity)->toContain('Quest complete')
        ->and(CharacterSkill::where('character_id', $character->id)->where('skill_id', Skill::CIRCUMSPECT_ID)->value('last_cast_at'))
        ->toBeBetween(now()->subMinute(), now());
});
