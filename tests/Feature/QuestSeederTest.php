<?php

use App\Models\Quest;
use Database\Seeders\QuestSeeder;

it('seeds the quest catalog metadata', function () {
    (new QuestSeeder)->run();

    // 2,399 seed rows, 131 duplicate ids skipped → 2,268 quests.
    expect(Quest::count())->toBe(2268);

    $quest = Quest::where('game_quest_id', 381)->first();
    expect($quest->name)->toBe('2008 Olympics')
        ->and($quest->giver)->toBe('Xu Zhihong')
        ->and($quest->item_rewards)->toBe(['Olympic Torch', 'Souvenir Torch'])
        ->and($quest->prerequisite)->toBeNull() // seed says "None"
        ->and($quest->repeatable)->toBeFalse()
        ->and($quest->steps_count)->toBe(1)
        ->and($quest->total_exp)->toBe(50000);

    expect(Quest::where('game_quest_id', 2617)->first()->repeatable)->toBeTrue();

    // Id 247 is reused for two different quests — the first occurrence wins.
    expect(Quest::where('game_quest_id', 247)->first()->name)->toBe('Zhul Set  Shield');
});

it('never overwrites quests the crawler has already mapped', function () {
    Quest::factory()->create([
        'game_quest_id' => 381,
        'name' => 'Crawled Name',
        'last_mapped_at' => now(),
    ]);

    (new QuestSeeder)->run();

    expect(Quest::where('game_quest_id', 381)->first()->name)->toBe('Crawled Name');
});

it('is idempotent', function () {
    (new QuestSeeder)->run();
    (new QuestSeeder)->run();

    expect(Quest::count())->toBe(2268);
});
