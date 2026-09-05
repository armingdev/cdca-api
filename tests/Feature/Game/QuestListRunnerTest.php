<?php

use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\QuestProgressStatus;
use App\Game\Quest\QuestListRunner;
use App\Models\Character;
use App\Models\CharacterQuestProgress;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\QuestList;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

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

it('parks the whole list when a quest hits the rage floor', function () {
    fakeQuestWorld(rage: 1000);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $catalog = seedQuestCatalog();
    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($catalog[742]->id);

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id, stopRage: 2500))
        ->run(log: fn (string $m) => null);

    // Rage comes back on its own, so the list waits on this quest rather than
    // abandoning the ones behind it.
    expect($summary->completed)->toBeFalse()
        ->and($summary->endReason)->toBe(RunEndReason::RageExhausted)
        ->and($summary->questsCompleted)->toBe(0)
        ->and($summary->stopReason)->toContain('Waiting on Street Crawler');
});

it('skips a quest wanting a purchased item and carries on with the rest of the list', function () {
    seedCollectQuestWorld();
    fakeCollectQuestWorld(objectiveItem: 'Quest Shard');

    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $shardQuest = Quest::factory()->create([
        'game_quest_id' => 1449,
        'name' => 'Primal Elemental Rune',
        'giver' => 'Rune Master',
    ]);
    // No giver in the catalog: a data gap no amount of waiting fixes, so it is
    // skipped outright rather than retried.
    $giverlessQuest = Quest::factory()->create(['game_quest_id' => 1450, 'name' => 'Nameless Errand', 'giver' => null]);

    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($shardQuest->id);
    $list->addQuest($giverlessQuest->id);

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))
        ->run(log: fn (string $m) => null);

    expect($summary->completed)->toBeTrue()
        ->and($summary->questsSkipped)->toBe(2)
        ->and($summary->questsCompleted)->toBe(0)
        ->and($summary->stopReason)->toBe('Quest list complete.');
});

it('retries a quest whose giver is momentarily absent before writing it off', function () {
    // The giver is mapped and the room loads, but the NPC is not rendered in
    // it — nothing about the quest is wrong, so a permanent skip would drop a
    // quest the character could have completed.
    seedCollectQuestWorld();
    fakeCollectQuestWorld(objectiveItem: 'Quest Shard');

    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $quest = Quest::factory()->create(['game_quest_id' => 1451, 'name' => 'Cleansing the Church', 'giver' => 'Stella']);
    $list = QuestList::create(['name' => 'Retry List']);
    $item = $list->addQuest($quest->id);

    $runner = fn (int $retries) => QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))
        ->run(log: fn (string $m) => null, questRetries: $retries);

    foreach ([0, 1] as $spent) {
        $summary = $runner($spent);

        expect($summary->endReason)->toBe(RunEndReason::TransientError)
            ->and($summary->questsSkipped)->toBe(0)
            ->and($summary->questRetries)->toBe($spent + 1)
            // Parked on the same item, so the resume re-enters this quest.
            ->and($summary->nextPosition)->toBe($item->position);
    }

    // Budget spent: now it is written off and the list moves on.
    $summary = $runner(QuestListRunner::MAX_QUEST_RETRIES);

    expect($summary->completed)->toBeTrue()
        ->and($summary->questsSkipped)->toBe(1)
        ->and($summary->endReason)->toBe(RunEndReason::Completed);
});

it('skips a quest it cannot make progress on rather than abandoning the list', function () {
    // The giver is mapped but the objective's mob is not, so quest 742 can
    // never advance — quest 743 behind it still must be reached.
    fakeQuestWorld();
    Mob::where('name', 'Street Crawler')->delete();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $catalog = seedQuestCatalog();
    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($catalog[742]->id);
    $list->addQuest($catalog[743]->id);

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))
        ->run(log: fn (string $m) => null);

    expect($summary->completed)->toBeTrue()
        ->and($summary->questsSkipped)->toBe(2)
        ->and($summary->stopReason)->toBe('Quest list complete.');
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

it('remembers a completed quest and walks straight past it next run', function () {
    fakeQuestWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $quest = Quest::factory()->create(['game_quest_id' => 742, 'name' => 'Street Crawler', 'giver' => 'Stella']);
    $list = QuestList::create(['name' => 'Memory List']);
    $list->addQuest($quest->id);

    $first = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))->run();

    expect($first->questsCompleted)->toBe(1)
        ->and(CharacterQuestProgress::where('character_id', $character->id)->where('quest_id', $quest->id)->value('status'))
        ->toBe(QuestProgressStatus::Completed);

    $requestsAfterFirst = count(Http::recorded());

    // A second run must not walk to the giver at all — that walk, repeated for
    // every settled quest, is what made a 200-quest list restart so slow.
    $second = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))->run();

    expect($second->completed)->toBeTrue()
        ->and($second->questsSkipped)->toBe(1)
        ->and($second->questsCompleted)->toBe(0)
        ->and(count(Http::recorded()))->toBe($requestsAfterFirst);
});

it('still runs a repeatable quest it has already completed', function () {
    fakeQuestWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $quest = Quest::factory()->create([
        'game_quest_id' => 742,
        'name' => 'Street Crawler',
        'giver' => 'Stella',
        'repeatable' => true,
    ]);
    CharacterQuestProgress::factory()->create([
        'character_id' => $character->id,
        'quest_id' => $quest->id,
    ]);

    $list = QuestList::create(['name' => 'Daily List']);
    $list->addQuest($quest->id);

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))->run();

    expect($summary->questsCompleted)->toBe(1)
        ->and($summary->questsSkipped)->toBe(0);
});

it('records a quest the giver does not offer, and runs it again once cleared', function () {
    fakeQuestWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    // Quest 999 is not among the giver's offers in the fake world.
    $quest = Quest::factory()->create(['game_quest_id' => 999, 'name' => 'Phantom Errand', 'giver' => 'Stella']);
    $list = QuestList::create(['name' => 'Phantom List']);
    $list->addQuest($quest->id);

    QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))->run();

    expect(CharacterQuestProgress::where('character_id', $character->id)->value('status'))
        ->toBe(QuestProgressStatus::Unavailable);

    $before = count(Http::recorded());

    QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))->run();

    expect(count(Http::recorded()))->toBe($before);

    // Clearing the record — what a player does after passing a level gate —
    // makes the run ask the giver again.
    CharacterQuestProgress::where('character_id', $character->id)->delete();

    QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))->run();

    expect(count(Http::recorded()))->toBeGreaterThan($before);
});

it('skips an in-progress quest no mob will open without recording it as unavailable', function () {
    // The 153-row incident: a quest merely under way was recorded
    // "unavailable", so every later run walked past it without looking.
    seedResumedQuestWorld();
    fakeResumedQuestWorld(offeredAtStella: false);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $quest = Quest::factory()->create([
        'game_quest_id' => 743,
        'name' => 'Cleansing the Church',
        'giver' => 'Sgt. Neatham',
    ]);

    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($quest->id);

    $log = [];
    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))
        ->run(log: function (string $m) use (&$log) {
            $log[] = $m;
        });

    expect($summary->completed)->toBeTrue()
        ->and($summary->questsSkipped)->toBe(1)
        ->and(collect($log)->contains(fn ($l) => str_contains($l, 'is in progress at step 3380')))->toBeTrue()
        ->and(CharacterQuestProgress::count())->toBe(0);
});

it('runs a quest whose step has moved past its giver instead of skipping it', function () {
    seedResumedQuestWorld();
    fakeResumedQuestWorld();

    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $quest = Quest::factory()->create([
        'game_quest_id' => 743,
        'name' => 'Cleansing the Church',
        'giver' => 'Sgt. Neatham',
    ]);

    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($quest->id);

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListId: $list->id))
        ->run(log: fn (string $m) => null);

    expect($summary->questsCompleted)->toBe(1)
        ->and($summary->questsSkipped)->toBe(0);

    $progress = CharacterQuestProgress::first();

    expect($progress->status)->toBe(QuestProgressStatus::Completed);
});
