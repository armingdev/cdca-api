<?php

use App\Game\Engine\QuestListRunConfig;
use App\Game\Quest\QuestListRunner;
use App\Models\Character;
use App\Models\QuestList;
use App\Models\Rga;

// Shared fake quest world (fakeQuestWorld / seedQuestWorld) lives in tests/Pest.php.
// The fake's NPC popup offers only quest 742 — quest 743 is therefore "not available".
beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    seedQuestWorld();
});

it('runs the list in order: completes available quests, skips already-completed ones', function () {
    fakeQuestWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $catalog = seedQuestCatalog();
    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($catalog[742]->id);   // available → runs to completion
    $list->addQuest($catalog[743]->id);   // not offered → skipped

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))
        ->run(log: fn (string $m) => null);

    expect($summary->completed)->toBeTrue()
        ->and($summary->questsCompleted)->toBe(1)
        ->and($summary->questsSkipped)->toBe(1)
        ->and($summary->kills)->toBe(5)
        ->and($summary->stopReason)->toBe('Quest list complete.');
});

it('stops the whole list when a quest gets stuck at the rage floor', function () {
    fakeQuestWorld(rage: 1000);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $catalog = seedQuestCatalog();
    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($catalog[742]->id);

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id, stopRage: 2500))
        ->run(log: fn (string $m) => null);

    expect($summary->completed)->toBeFalse()
        ->and($summary->questsCompleted)->toBe(0)
        ->and($summary->stopReason)->toContain('Stopped on Street Crawler');
});

it('drives the whole list through the outwar:questlist-run command', function () {
    fakeQuestWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $catalog = seedQuestCatalog();
    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($catalog[742]->id);

    $this->artisan('outwar:questlist-run', ['character' => $character->id, 'list' => 'Armins List'])
        ->assertSuccessful()
        ->expectsOutputToContain('List complete');
});
