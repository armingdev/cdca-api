<?php

use App\Game\Enums\QuestObjectiveType;
use App\Game\Exceptions\ParseException;
use App\Game\Parsers\WorldQuestHelperParser;

it('parses kill and collect "find my target" toggles from the tracker', function () {
    $quests = new WorldQuestHelperParser()->parse(gameFixture('quest/world_questhelper_toggles.json'));
    $toggles = collect($quests)->flatMap(fn ($quest) => $quest->toggles());

    expect($toggles)->toHaveCount(2);

    $kill = $toggles->firstWhere('questId', 124);

    expect($kill->mobId)->toBe(390)
        ->and($kill->itemName)->toBe('')
        ->and($kill->isCollect())->toBeFalse()
        ->and($kill->stepId)->toBe(344)
        ->and($kill->conditionId)->toBe(6751);

    $collect = $toggles->firstWhere('questId', 125);

    expect($collect->mobId)->toBe(0)
        ->and($collect->itemName)->toBe('Uncut Emerald')
        ->and($collect->isCollect())->toBeTrue()
        ->and($collect->stepId)->toBe(346)
        ->and($collect->conditionId)->toBe(1816);
});

it('throws on a body without a qtable', function () {
    new WorldQuestHelperParser()->parse('<html>login</html>');
})->throws(ParseException::class);

it('reads every in-progress quest with the step the character stands on', function () {
    $quests = collect(new WorldQuestHelperParser()->parse(gameFixture('quest/world_questhelper_active.json')))
        ->keyBy('questId');

    expect($quests->keys()->all())->toBe([672, 946, 812, 125, 573, 2314])
        ->and($quests[946]->name)->toBe('Conjured Plague')
        ->and($quests[946]->stepId)->toBe(4809)
        ->and($quests[2314]->objectives)->toHaveCount(8);
});

it('reads a talk-only step as the mob to go and see', function () {
    // Quest 672's giver is Sgt. Neatham, but this character's current step
    // belongs to Stella — the exact shape that used to read as "not offered"
    // at the giver and get the quest skipped as complete.
    $quests = collect(new WorldQuestHelperParser()->parse(gameFixture('quest/world_questhelper_active.json')))
        ->keyBy('questId');

    $quest = $quests[672];
    $talk = $quest->objectives[0];

    expect($quest->stepId)->toBe(3071)
        ->and($quest->talkTarget())->toBe('Stella')
        ->and($talk->type)->toBe(QuestObjectiveType::Talk)
        ->and($talk->target)->toBe('Stella')
        ->and($talk->mobId)->toBe(868)
        ->and($talk->conditionId)->toBe(0)
        ->and($quest->unmetObjectives())->toBe([]);
});

it('reads a finished step as a turn-in at the named mob', function () {
    $quests = collect(new WorldQuestHelperParser()->parse(gameFixture('quest/world_questhelper_active.json')))
        ->keyBy('questId');

    $quest = $quests[946];

    expect($quest->talkTarget())->toBe('Hadley')
        ->and($quest->unmetObjectives())->toBe([]);

    [$kill, $collect, $talk] = $quest->objectives;

    expect($kill->type)->toBe(QuestObjectiveType::Kill)
        ->and($kill->target)->toBe('Mutated Jaxalo')
        ->and($kill->current)->toBe(50)
        ->and($kill->required)->toBe(50)
        ->and($kill->complete)->toBeTrue()
        ->and($kill->mobId)->toBe(1230)
        ->and($collect->type)->toBe(QuestObjectiveType::Collect)
        ->and($collect->target)->toBe('Quest Shard')
        ->and($collect->complete)->toBeTrue()
        ->and($collect->mobId)->toBe(0)
        ->and($talk->type)->toBe(QuestObjectiveType::Talk)
        ->and($talk->target)->toBe('Hadley');
});

it('separates met from unmet objectives on a part-finished step', function () {
    $quests = collect(new WorldQuestHelperParser()->parse(gameFixture('quest/world_questhelper_active.json')))
        ->keyBy('questId');

    $quest = $quests[812];

    // A step still short of a count renders no talk row at all.
    expect($quest->talkTarget())->toBeNull()
        ->and($quest->objectives[0]->complete)->toBeTrue()
        ->and($quest->objectives[0]->target)->toBe('Warrior Robot');

    $unmet = $quest->unmetObjectives();

    expect($unmet)->toHaveCount(1)
        ->and($unmet[0]->target)->toBe('Winged Avenger')
        ->and($unmet[0]->current)->toBe(0)
        ->and($unmet[0]->required)->toBe(8)
        ->and($unmet[0]->remaining())->toBe(8);
});

it('reads a step mixing a collect item with a kill', function () {
    $quests = collect(new WorldQuestHelperParser()->parse(gameFixture('quest/world_questhelper_active.json')))
        ->keyBy('questId');

    $quest = $quests[573];

    expect($quest->stepId)->toBe(2529)
        ->and($quest->objectives[0]->type)->toBe(QuestObjectiveType::Collect)
        ->and($quest->objectives[0]->target)->toBe('Basilisk Thorn')
        ->and($quest->objectives[0]->complete)->toBeFalse()
        ->and($quest->objectives[1]->type)->toBe(QuestObjectiveType::Kill)
        ->and($quest->objectives[1]->target)->toBe('Lukent Fighter')
        ->and($quest->objectives[1]->complete)->toBeTrue()
        ->and($quest->unmetObjectives())->toHaveCount(1);
});

it('returns no quests for a tracker with nothing in progress', function () {
    expect(new WorldQuestHelperParser()->parse(json_encode(['qtable' => ''])))->toBe([]);
});
