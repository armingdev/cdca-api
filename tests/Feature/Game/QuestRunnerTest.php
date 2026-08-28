<?php

use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Exceptions\GameException;
use App\Game\Quest\QuestRunner;
use App\Models\Character;
use App\Models\QuestItem;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

// The stateful fake quest world (fakeQuestWorld / seedQuestWorld) lives in tests/Pest.php.
beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    seedQuestWorld();
});

it('runs quest 742 end to end: accept → farm 5 Street Crawlers → turn in', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeQuestWorld();

    $log = [];
    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Stella',
        questId: 742,
    ))->run(log: function (string $m) use (&$log) {
        $log[] = $m;
    });

    expect($summary->completed)->toBeTrue()
        ->and($summary->stepsCompleted)->toBe(1)
        ->and($summary->expGained)->toBe(300)
        ->and($summary->kills)->toBe(5)
        ->and($summary->stopReason)->toBe('Quest complete.')
        ->and(collect($log)->contains(fn ($l) => str_contains($l, 'Objective: Street Crawler')))->toBeTrue();
});

it('stops with a clear reason when the quest is not available at the giver', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeQuestWorld();

    expect(fn () => QuestRunner::forCharacter($character, new QuestRunConfig(npcName: 'Stella', questId: 999))->run())
        ->toThrow(GameException::class, 'not available');
});

it('reports a clear failure when the giver is not mapped', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeQuestWorld();

    expect(fn () => QuestRunner::forCharacter($character, new QuestRunConfig(npcName: 'Nobody', questId: 742))->run())
        ->toThrow(GameException::class, 'not in the mapped world');
});

it('fulfills a collect objective by farming the seeded source mob', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    seedCollectQuestWorld();
    QuestItem::factory()->create([
        'name' => 'Holy Elemental Crystal',
        'source_mobs' => ['Holy Elemental Keeper'],
    ]);

    fakeCollectQuestWorld();

    $log = [];
    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Rune Master',
        questId: 1449,
    ))->run(log: function (string $m) use (&$log) {
        $log[] = $m;
    });

    expect($summary->completed)->toBeTrue()
        ->and($summary->kills)->toBe(1)
        ->and($summary->stopReason)->toBe('Quest complete.')
        ->and(collect($log)->contains(fn ($l) => str_contains($l, 'Objective: Holy Elemental Crystal 0/1 collect')))->toBeTrue();
});

it('learns collect sources by following the quest-helper compass when nothing is known', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    seedCollectQuestWorld();
    // No QuestItem seeded and no battle drops — the runner knows nothing.

    fakeCollectQuestWorld();

    $log = [];
    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Rune Master',
        questId: 1449,
    ))->run(log: function (string $m) use (&$log) {
        $log[] = $m;
    });

    expect($summary->completed)->toBeTrue()
        ->and($summary->kills)->toBe(1)
        ->and(collect($log)->contains(fn ($l) => str_contains($l, 'quest-helper compass')))->toBeTrue()
        ->and(collect($log)->contains(fn ($l) => str_contains($l, 'Compass arrived in room 2')))->toBeTrue();

    $item = QuestItem::where('name', 'Holy Elemental Crystal')->first();

    expect($item->target_room_id)->toBe(2)
        ->and($item->helper_verified_at)->not->toBeNull();
});

it('stops with a clear reason when a collect item has no known source and no helper toggle', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    seedCollectQuestWorld();

    fakeCollectQuestWorld(helper: false);

    $log = [];
    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Rune Master',
        questId: 1449,
    ))->run(log: function (string $m) use (&$log) {
        $log[] = $m;
    });

    expect($summary->completed)->toBeFalse()
        ->and($summary->stopReason)->toBe("Could not make progress on objective 'Holy Elemental Crystal'.")
        ->and(collect($log)->contains(fn ($l) => str_contains($l, 'No known way to fulfill')))->toBeTrue();
});

it('parks a kill objective for respawn when the world runs out of live targets', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    // Quest 742 needs 5 kills but only 2 Street Crawlers are alive.
    fakeQuestWorld(liveMobs: 2);

    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Stella',
        questId: 742,
    ))->run();

    expect($summary->completed)->toBeFalse()
        ->and($summary->endReason)->toBe(RunEndReason::TargetsDepleted)
        ->and($summary->kills)->toBe(2)
        ->and($summary->stopReason)->toBe("All 'Street Crawler' targets are dead — waiting for respawn.");
});

it('parks for respawn when a cleared spawn room renders no target at all', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    // The corpse flag is the assumption that broke in production: the live
    // game dropped the entry outright, and the pass read as "stuck".
    fakeQuestWorld(liveMobs: 2, clearedRendersCorpse: false);

    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Stella',
        questId: 742,
    ))->run();

    expect($summary->completed)->toBeFalse()
        ->and($summary->endReason)->toBe(RunEndReason::TargetsDepleted)
        ->and($summary->kills)->toBe(2)
        ->and($summary->stopReason)->toBe("All 'Street Crawler' targets are dead — waiting for respawn.");
});

it('parks instead of attacking when the target costs more rage than the character holds', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeQuestWorld(rage: 1000, mobRage: 2000);

    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Stella',
        questId: 742,
        stopRage: 0,
    ))->run();

    expect($summary->completed)->toBeFalse()
        ->and($summary->endReason)->toBe(RunEndReason::RageInsufficient)
        ->and($summary->kills)->toBe(0)
        ->and($summary->stopReason)->toBe('Street Crawler costs 2,000 rage and the character holds 1,000.');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'somethingelse.php'));
});

it('gives up on a target whose attacks the game keeps refusing', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    // A refusal costs no rage and leaves the mob standing, so without a
    // circuit breaker the loop re-attacks the same encounter forever.
    fakeQuestWorld(attacksRefused: true);

    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Stella',
        questId: 742,
    ))->run();

    expect($summary->endReason)->toBe(RunEndReason::Stuck)
        ->and($summary->kills)->toBe(0);

    $attacks = collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'somethingelse.php'))
        ->count();

    expect($attacks)->toBe(5);
});

it('skips a step wanting a purchased item instead of farming for it', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    seedCollectQuestWorld();
    fakeCollectQuestWorld(objectiveItem: 'Quest Shard');

    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Rune Master',
        questId: 1449,
    ))->run();

    expect($summary->completed)->toBeFalse()
        ->and($summary->endReason)->toBe(RunEndReason::RequiresPurchasedItem)
        ->and($summary->stopReason)->toBe("Quest 1449 needs 'Quest Shard', which the game only sells.");

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'somethingelse.php'));
});

it('farms a purchased item like any other when the skip option is off', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    seedCollectQuestWorld();
    QuestItem::factory()->create(['name' => 'Quest Shard', 'source_mobs' => ['Holy Elemental Keeper']]);
    fakeCollectQuestWorld(objectiveItem: 'Quest Shard', liveKills: 1, drops: false);

    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Rune Master',
        questId: 1449,
        skipShardQuests: false,
    ))->run();

    expect($summary->endReason)->not->toBe(RunEndReason::RequiresPurchasedItem)
        ->and($summary->kills)->toBe(1);
});

it('parks a collect objective for respawn when the source mobs are all dead', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    seedCollectQuestWorld();
    QuestItem::factory()->create([
        'name' => 'Holy Elemental Crystal',
        'source_mobs' => ['Holy Elemental Keeper'],
    ]);

    // Two Keepers alive, neither drops the crystal — the pool empties before
    // the objective is met, exactly the "kill everything, still need items" case.
    fakeCollectQuestWorld(liveKills: 2, drops: false);

    $summary = QuestRunner::forCharacter($character, new QuestRunConfig(
        npcName: 'Rune Master',
        questId: 1449,
    ))->run();

    expect($summary->completed)->toBeFalse()
        ->and($summary->endReason)->toBe(RunEndReason::TargetsDepleted)
        ->and($summary->kills)->toBe(2)
        ->and($summary->stopReason)->toBe("All 'Holy Elemental Crystal' targets are dead — waiting for respawn.");
});

it('drives the whole flow through the outwar:quest command', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    fakeQuestWorld();

    $this->artisan('outwar:quest', [
        'character' => $character->id,
        '--npc' => 'Stella',
        '--quest' => 742,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Quest complete');
});
