<?php

use App\Game\Engine\QuestRunConfig;
use App\Game\Exceptions\GameException;
use App\Game\Quest\QuestRunner;
use App\Models\Character;
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
