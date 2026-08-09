<?php

use App\Game\Auth\LoginService;
use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Engine\MobRunSummary;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\RunMode;
use App\Game\Enums\RunStatus;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\QuestList;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    seedCombatWorld();

    $this->character = Character::factory()->for(Rga::factory()->withSession())->create();
});

function losingMobRun(Character $character, bool $smart): MobRunSummary
{
    return MobRunner::forCharacter($character, new MobRunConfig(
        mobNames: ['Kix Harvester'],
        smart: $smart,
    ))->run();
}

function timesRequested(string $needle): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), $needle))
        ->count();
}

it('levels up after a loss before attacking again', function () {
    fakeLosingWorld(levelUps: 1);

    losingMobRun($this->character, smart: true);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'levelup.php'));
});

it('gives up on a mob after three straight losses instead of grinding rage', function () {
    fakeLosingWorld(levelUps: 0);

    $summary = losingMobRun($this->character, smart: true);

    expect($summary->endReason)->toBe(RunEndReason::Outmatched)
        ->and($summary->losses)->toBe(3)
        ->and($summary->wins)->toBe(0)
        ->and($summary->stopReason)->toContain('Outmatched by Kix Harvester')
        // Exactly three attacks: rage (50k against a 150 cost) was never the limit.
        ->and(timesRequested('somethingelse.php'))->toBe(3);
});

it('resets the loss streak when a level-up lands', function () {
    // Each level-up resets the streak, so two of them buy two extra attempts.
    fakeLosingWorld(levelUps: 2);

    $summary = losingMobRun($this->character, smart: true);

    expect($summary->endReason)->toBe(RunEndReason::Outmatched)
        ->and($summary->losses)->toBe(5);
});

it('grinds to the rage floor without smart mode, never levelling or re-gearing', function () {
    // 3000 rage, 150 per attack, 2500 floor → four attacks before the floor bites.
    fakeLosingWorld(rage: 3000);

    $summary = losingMobRun($this->character, smart: false);

    expect($summary->endReason)->toBe(RunEndReason::RageExhausted)
        ->and($summary->losses)->toBe(4)
        ->and(timesRequested('somethingelse.php'))->toBe(4);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'levelup.php'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'backpackcontents.php'));
});

it('checks gear on every loss so a fresh drop can be equipped', function () {
    fakeLosingWorld(levelUps: 0);

    losingMobRun($this->character, smart: true);

    // One pass-start scan plus one after each of the three losses.
    expect(timesRequested('backpackcontents.php'))->toBe(4);
});

it('stops the quest run when the objective mob is outmatched', function () {
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeLosingQuestWorld();

    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => RunMode::Quest,
            'config' => ['npc_name' => 'Stella', 'quest_id' => 742, 'stop_rage' => 2500, 'smart' => true],
            'status' => RunStatus::Running,
        ]))
        ->for($this->character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($participant->fresh()->last_activity)->toContain('Outmatched by Street Crawler')
        ->and($participant->fresh()->losses)->toBe(3);
});

it('stops a cycling mob run outright when outmatched instead of scheduling another pass', function () {
    fakeLosingWorld(levelUps: 0);

    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'config' => [
                'mob_names' => ['Kix Harvester'],
                'smart' => true,
                'run_count' => 5,
                'attack_interval_seconds' => 300,
            ],
            'status' => RunStatus::Running,
        ]))
        ->for($this->character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Stopped)
        ->and($participant->fresh()->resume_at)->toBeNull()
        ->and($participant->fresh()->last_activity)->toContain('Outmatched');
});

it('spends banked exp to reach a quest required level before walking to the giver', function () {
    Quest::factory()->create([
        'game_quest_id' => 742,
        'name' => 'Street Crawler',
        'giver' => 'Stella',
        'required_level' => 12,
    ]);
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeQuestWorld(level: 10, levelUps: 2);

    $list = QuestList::create(['name' => 'Smart List']);
    $list->addQuest(Quest::where('game_quest_id', 742)->value('id'));

    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => RunMode::QuestList,
            'config' => ['quest_list_id' => $list->id, 'stop_rage' => 2500, 'smart' => true],
            'status' => RunStatus::Running,
        ]))
        ->for($this->character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect(timesRequested('levelup.php'))->toBe(2)
        ->and($this->character->fresh()->level)->toBe(12);
});

it('does not touch levelup when the character already meets the quest level', function () {
    Quest::factory()->create([
        'game_quest_id' => 742,
        'name' => 'Street Crawler',
        'giver' => 'Stella',
        'required_level' => 5,
    ]);
    Mob::factory()->create(['name' => 'Stella'])->rooms()->attach(1, ['last_seen_at' => now()]);
    Mob::factory()->create(['name' => 'Street Crawler'])->rooms()->attach(2, ['last_seen_at' => now()]);
    fakeQuestWorld();

    $list = QuestList::create(['name' => 'Smart List']);
    $list->addQuest(Quest::where('game_quest_id', 742)->value('id'));

    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'mode' => RunMode::QuestList,
            'config' => ['quest_list_id' => $list->id, 'stop_rage' => 2500, 'smart' => true],
            'status' => RunStatus::Running,
        ]))
        ->for($this->character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    expect($participant->fresh()->status)->toBe(RunStatus::Completed);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'levelup.php'));
});
