<?php

use App\Game\Engine\QuestRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Exceptions\GameException;
use App\Game\Quest\QuestRunner;
use App\Models\Character;
use App\Models\QuestItem;
use App\Models\Rga;

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
