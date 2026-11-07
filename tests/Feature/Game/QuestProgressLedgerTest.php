<?php

use App\Game\Enums\QuestProgressStatus;
use App\Game\Quest\QuestProgressLedger;
use App\Models\Character;
use App\Models\CharacterQuestProgress;
use App\Models\Quest;

/**
 * A giver is silent to a character below the quest's level whether or not the
 * quest is done, so that silence proves nothing. Prod held 663 such rows in
 * Nov 2026, each skipping a quest for good on every later run.
 */
it('trusts an unavailable verdict only when it was recorded at the quest level or above', function (int $recordedAt, bool $skippable) {
    $character = Character::factory()->create(['level' => 95]);
    $quest = Quest::factory()->create(['required_level' => 90]);
    CharacterQuestProgress::factory()->unavailable()->for($character)->for($quest)->create(['context' => ['level' => $recordedAt]]);

    $skippableIds = app(QuestProgressLedger::class)->skippableQuestIds($character, [$quest->id]);

    expect($skippableIds)->toBe($skippable ? [$quest->id] : []);
})->with([
    'recorded below the quest level' => [83, false],
    'recorded at the quest level' => [90, true],
]);

it('does not record a quest as unavailable while the character is below its level', function () {
    $character = Character::factory()->create(['level' => 83]);
    $quest = Quest::factory()->create(['required_level' => 90]);

    app(QuestProgressLedger::class)->recordUnavailable($character, $quest);

    $this->assertDatabaseCount('character_quest_progress', 0);
});

it('clears unavailable verdicts on the quests a just-completed quest unlocks', function () {
    $character = Character::factory()->create(['level' => 95]);
    $prerequisite = Quest::factory()->create(['name' => 'Intergalactic Union']);
    $unlocked = Quest::factory()->create(['prerequisite' => 'Intergalactic Union', 'prerequisite_quest_id' => $prerequisite->id]);
    $unrelated = Quest::factory()->create();
    $unlockedVerdict = CharacterQuestProgress::factory()->unavailable()->for($character)->for($unlocked)->create();
    $unrelatedVerdict = CharacterQuestProgress::factory()->unavailable()->for($character)->for($unrelated)->create();

    app(QuestProgressLedger::class)->recordCompleted($character, $prerequisite);

    $this->assertModelMissing($unlockedVerdict);
    $this->assertModelExists($unrelatedVerdict);
    $this->assertDatabaseHas('character_quest_progress', [
        'character_id' => $character->id,
        'quest_id' => $prerequisite->id,
        'status' => QuestProgressStatus::Completed->value,
    ]);
});
