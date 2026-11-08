<?php

use App\Game\Engine\QuestListProgress;
use App\Game\Engine\QuestListRunConfig;
use App\Game\Engine\RunEndReason;
use App\Game\Enums\QuestProgressStatus;
use App\Game\Exceptions\GameException;
use App\Game\Exceptions\GameServerException;
use App\Game\Exceptions\GatedRoomException;
use App\Game\Quest\QuestListRunner;
use App\Game\World\GatedRooms;
use App\Models\Character;
use App\Models\CharacterQuestProgress;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\QuestList;
use App\Models\Rga;
use App\Models\Room;
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

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))
        ->run(log: fn (string $m) => null);

    expect($summary->completed)->toBeTrue()
        ->and($summary->questsCompleted)->toBe(1)
        ->and($summary->questsSkipped)->toBe(1)
        ->and($summary->kills)->toBe(5)
        ->and($summary->stopReason)->toBe('Quest list complete: 1 completed, 1 skipped.');
});

it('parks the whole list when a quest hits the rage floor', function () {
    fakeQuestWorld(rage: 1000);

    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    $catalog = seedQuestCatalog();
    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($catalog[742]->id);

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id], stopRage: 2500))
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

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))
        ->run(log: fn (string $m) => null);

    expect($summary->completed)->toBeTrue()
        ->and($summary->questsSkipped)->toBe(2)
        ->and($summary->questsCompleted)->toBe(0)
        ->and($summary->stopReason)->toBe('Quest list complete: 0 completed, 2 skipped.');
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

    $runner = fn (int $retries) => QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))
        ->run(log: fn (string $m) => null, from: new QuestListProgress(questRetries: $retries));

    foreach ([0, 1] as $spent) {
        $summary = $runner($spent);

        expect($summary->endReason)->toBe(RunEndReason::TransientError)
            ->and($summary->questsSkipped)->toBe(0)
            ->and($summary->progress->questRetries)->toBe($spent + 1)
            // Parked on the same item, so the resume re-enters this quest.
            ->and($summary->progress->position)->toBe($item->position);
    }

    // Budget spent: now it is written off and the list moves on.
    $summary = $runner(QuestListRunner::MAX_QUEST_RETRIES);

    expect($summary->completed)->toBeTrue()
        ->and($summary->questsSkipped)->toBe(1)
        ->and($summary->endReason)->toBe(RunEndReason::Completed);
});

/**
 * An outage says nothing about the quest. Caught as a GameException anywhere
 * on the way up — QuestRunner's farming step catches those — it would read as
 * "no progress possible" and skip a quest the character can finish; it must
 * reach the job, which parks the list on this quest and retries.
 */
it('lets a game server outage mid-quest through instead of skipping the quest', function () {
    Http::fake(['*somethingelse.php*' => Http::response('error code: 521', 521)]);
    fakeQuestWorld();
    $character = Character::factory()->for(Rga::factory()->withSession())->create();
    $catalog = seedQuestCatalog();
    $list = QuestList::create(['name' => 'Armins List']);
    $list->addQuest($catalog[742]->id);

    $run = fn () => QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))
        ->run(log: fn (string $m) => null);

    expect($run)->toThrow(GameServerException::class, 'HTTP 521');
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

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))
        ->run(log: fn (string $m) => null);

    expect($summary->completed)->toBeTrue()
        ->and($summary->questsSkipped)->toBe(2)
        ->and($summary->stopReason)->toBe('Quest list complete: 0 completed, 2 skipped.');
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

    $first = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

    expect($first->questsCompleted)->toBe(1)
        ->and(CharacterQuestProgress::where('character_id', $character->id)->where('quest_id', $quest->id)->value('status'))
        ->toBe(QuestProgressStatus::Completed);

    $requestsAfterFirst = count(Http::recorded());

    // A second run must not walk to the giver at all — that walk, repeated for
    // every settled quest, is what made a 200-quest list restart so slow.
    $second = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

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

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

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

    QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

    expect(CharacterQuestProgress::where('character_id', $character->id)->value('status'))
        ->toBe(QuestProgressStatus::Unavailable);

    $before = count(Http::recorded());

    QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

    expect(count(Http::recorded()))->toBe($before);

    // Clearing the record — what a player does after passing a level gate —
    // makes the run ask the giver again.
    CharacterQuestProgress::where('character_id', $character->id)->delete();

    QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

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
    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))
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

    $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))
        ->run(log: fn (string $m) => null);

    expect($summary->questsCompleted)->toBe(1)
        ->and($summary->questsSkipped)->toBe(0);

    $progress = CharacterQuestProgress::first();

    expect($progress->status)->toBe(QuestProgressStatus::Completed);
});

describe('chained lists', function () {
    it('plays several lists back to back, skipping in a later list what an earlier one finished', function () {
        fakeQuestWorld();

        $character = Character::factory()->for(Rga::factory()->withSession())->create();
        $catalog = seedQuestCatalog();
        $first = QuestList::create(['name' => 'First']);
        $first->addQuest($catalog[742]->id);   // completes
        $first->addQuest($catalog[743]->id);   // not offered → skipped
        $second = QuestList::create(['name' => 'Second']);
        $second->addQuest($catalog[742]->id);  // done in the first list → ledger skip

        $settled = [];

        $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$first->id, $second->id]))
            ->run(
                log: fn (string $m) => null,
                onProgress: function (QuestListProgress $progress) use (&$settled): void {
                    $settled[] = [$progress->listIndex, $progress->position];
                },
            );

        expect($summary->completed)->toBeTrue()
            ->and($summary->questsCompleted)->toBe(1)
            ->and($summary->questsSkipped)->toBe(2)
            ->and($summary->kills)->toBe(5)
            ->and($summary->stopReason)->toBe('All quest lists complete: 1 completed, 2 skipped.')
            ->and($summary->progress->listIndex)->toBe(1)
            // Each item of the first list, the hop to the second, then its item.
            ->and($settled)->toBe([[0, 2], [0, 3], [1, 0], [1, 2]]);
    });

    it('resumes inside a later list without replaying the earlier ones', function () {
        fakeQuestWorld();

        $character = Character::factory()->for(Rga::factory()->withSession())->create();
        $catalog = seedQuestCatalog();
        $first = QuestList::create(['name' => 'First']);
        $first->addQuest($catalog[742]->id);
        $second = QuestList::create(['name' => 'Second']);
        $second->addQuest($catalog[743]->id);

        $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$first->id, $second->id]))
            ->run(log: fn (string $m) => null, from: new QuestListProgress(listIndex: 1));

        expect($summary->completed)->toBeTrue()
            ->and($summary->questsCompleted)->toBe(0)
            ->and($summary->questsSkipped)->toBe(1)
            ->and($summary->kills)->toBe(0);
    });

    it('carries on past a list deleted after the run started', function () {
        fakeQuestWorld();

        $character = Character::factory()->for(Rga::factory()->withSession())->create();
        $catalog = seedQuestCatalog();
        $gone = QuestList::create(['name' => 'Gone']);
        $kept = QuestList::create(['name' => 'Kept']);
        $kept->addQuest($catalog[742]->id);
        $goneId = $gone->id;
        $gone->delete();

        $log = [];
        $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$goneId, $kept->id]))
            ->run(log: function (string $m) use (&$log): void {
                $log[] = $m;
            });

        expect($summary->completed)->toBeTrue()
            ->and($summary->questsCompleted)->toBe(1)
            ->and($log)->toContain("Quest list #{$goneId} no longer exists — skipping it.");
    });

    it('fails when none of its lists exist', function () {
        $character = Character::factory()->for(Rga::factory()->withSession())->create();

        QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [999998, 999999]))->run();
    })->throws(GameException::class, 'Quest list #999998, #999999 not found.');
});

/**
 * A list ordered top level first sent a level-83 character to every level-90
 * giver, read each silence as "unavailable", and reported the list complete
 * with nothing done (prod run 107, Nov 2026).
 */
describe('quests the character is not ready for', function () {
    it('defers a quest above the character level without walking to its giver', function () {
        fakeQuestWorld(level: 20);
        $character = Character::factory()->for(Rga::factory()->withSession())->create(['level' => 20]);
        $quest = Quest::factory()->create(['game_quest_id' => 742, 'name' => 'Street Crawler', 'giver' => 'Stella', 'required_level' => 90]);
        $list = QuestList::create(['name' => 'Endgame First']);
        $list->addQuest($quest->id);

        $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

        expect($summary->completed)->toBeTrue()
            ->and($summary->questsSkipped)->toBe(0)
            ->and($summary->progress->deferred)->toBe([$quest->id => QuestListProgress::DEFERRED_LEVEL])
            ->and($summary->stopReason)->toBe('Quest list done for now: 0 completed, 1 deferred until level 90.');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'world_questHelper.php'));
        $this->assertDatabaseCount('character_quest_progress', 0);
    });

    it('levels the character into a quest when the run may level', function () {
        fakeQuestWorld(level: 20, levelUps: 1);
        $character = Character::factory()->for(Rga::factory()->withSession())->create(['level' => 20]);
        $quest = Quest::factory()->create(['game_quest_id' => 742, 'name' => 'Street Crawler', 'giver' => 'Stella', 'required_level' => 21]);
        $list = QuestList::create(['name' => 'Just Above']);
        $list->addQuest($quest->id);

        $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id], levelUp: true))->run();

        expect($summary->questsCompleted)->toBe(1)
            ->and($summary->progress->deferred)->toBe([])
            ->and($character->fresh()->level)->toBe(21);
    });

    it('defers a quest whose prerequisite is itself deferred', function () {
        fakeQuestWorld(level: 20);
        $character = Character::factory()->for(Rga::factory()->withSession())->create(['level' => 20]);
        $keyQuest = Quest::factory()->create(['game_quest_id' => 2630, 'name' => 'Intergalactic Union', 'giver' => 'Zugas', 'required_level' => 90]);
        $behindIt = Quest::factory()->create([
            'game_quest_id' => 742,
            'name' => 'Street Crawler',
            'giver' => 'Stella',
            'prerequisite' => 'Intergalactic Union',
            'prerequisite_quest_id' => $keyQuest->id,
        ]);
        $list = QuestList::create(['name' => 'Triworld']);
        $list->addQuest($keyQuest->id);
        $list->addQuest($behindIt->id);

        $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

        expect($summary->progress->deferred)->toBe([
            $keyQuest->id => QuestListProgress::DEFERRED_LEVEL,
            $behindIt->id => QuestListProgress::DEFERRED_PREREQUISITE,
        ])->and($summary->questsSkipped)->toBe(0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'world_questHelper.php'));
    });

    it('replays a deferred quest once the character has grown into it', function () {
        // The level the quest is deferred on, then the level read when the
        // pass ends — the character levelled while the list played.
        $statsReads = 0;
        Http::fake(function ($request) use (&$statsReads) {
            if (! str_contains($request->url(), 'userstats.php')) {
                return null;
            }

            $statsReads++;

            return Http::response(json_encode(['exp' => '1,000', 'rage' => '50,000', 'level' => $statsReads === 1 ? '20' : '21', 'width' => 0]));
        });
        fakeQuestWorld();
        $character = Character::factory()->for(Rga::factory()->withSession())->create(['level' => 20]);
        $quest = Quest::factory()->create(['game_quest_id' => 742, 'name' => 'Street Crawler', 'giver' => 'Stella', 'required_level' => 21]);
        $list = QuestList::create(['name' => 'Just Above']);
        $list->addQuest($quest->id);

        $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

        expect($summary->completed)->toBeTrue()
            ->and($summary->questsCompleted)->toBe(1)
            ->and($summary->progress->pass)->toBe(2)
            ->and($summary->progress->deferred)->toBe([])
            ->and($summary->stopReason)->toBe('Quest list complete: 1 completed.');
    });

    it('plays only the deferred quests when a re-pass resumes, carrying the run tallies on', function () {
        fakeQuestWorld();
        $character = Character::factory()->for(Rga::factory()->withSession())->create();
        $catalog = seedQuestCatalog();
        $list = QuestList::create(['name' => 'Second Pass']);
        $list->addQuest($catalog[743]->id);   // settled on pass 1 — must not be replayed
        $list->addQuest($catalog[742]->id);   // deferred on pass 1

        $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))
            ->run(from: new QuestListProgress(completed: 4, skipped: 3, pass: 2, retrying: [$catalog[742]->id]));

        expect($summary->questsCompleted)->toBe(1)
            ->and($summary->questsSkipped)->toBe(0)
            ->and($summary->progress->completed)->toBe(5)
            ->and($summary->progress->skipped)->toBe(3);
    });
});

describe('gates the character was turned away from', function () {
    it('skips a quest whose giver is past a known gate without walking to it', function () {
        Room::whereKey(2)->update(['east' => 3]);
        Room::factory()->create(['id' => 3, 'west' => 2]);
        Mob::factory()->create(['name' => 'Trelon'])->rooms()->attach(3, ['last_seen_at' => now()]);
        $quest = Quest::factory()->create(['game_quest_id' => 2631, 'name' => 'Triworld Sanctuary', 'giver' => 'Trelon']);
        $list = QuestList::create(['name' => 'Triworld']);
        $list->addQuest($quest->id);
        fakeQuestWorld();
        $character = Character::factory()->for(Rga::factory()->withSession())->create();
        app(GatedRooms::class)->remember($character, new GatedRoomException(2, 'To enter this room you must be carrying Triworld Teleporter.'));
        $log = [];

        $summary = QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))
            ->run(log: function (string $m) use (&$log): void {
                $log[] = $m;
            });

        expect($summary->questsSkipped)->toBe(1)
            ->and($summary->progress->skippedByReason)->toBe(['unreachable' => 1])
            ->and($log)->toContain("Skipping Triworld Sanctuary: Quest-giver 'Trelon' is past gated room 2 (Triworld Teleporter).");
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ajax_changeroomb.php') && $request['room'] === '2');
    });

    it('forgets them once a quest completes, since it may have handed out the key', function () {
        fakeQuestWorld();
        $character = Character::factory()->for(Rga::factory()->withSession())->create();
        app(GatedRooms::class)->remember($character, new GatedRoomException(99, 'To enter this room you must be carrying Triworld Teleporter.'));
        $catalog = seedQuestCatalog();
        $list = QuestList::create(['name' => 'Key Giver']);
        $list->addQuest($catalog[742]->id);

        QuestListRunner::forCharacter($character, new QuestListRunConfig(questListIds: [$list->id]))->run();

        expect(app(GatedRooms::class)->for($character))->toBe([]);
    });
});
