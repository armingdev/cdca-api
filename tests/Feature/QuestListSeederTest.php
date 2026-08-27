<?php

use App\Models\Quest;
use App\Models\QuestList;
use Database\Seeders\QuestListSeeder;
use Database\Seeders\QuestSeeder;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    (new QuestSeeder)->run();
});

it('seeds the Test Road to 95 list in file order', function () {
    (new QuestListSeeder)->run();

    $list = QuestList::firstWhere('name', 'Test Road to 95');

    expect($list)->not->toBeNull()
        ->and($list->user_id)->toBeNull();

    $names = json_decode(file_get_contents(database_path('data/quest_lists.json')), true)[0]['quests'];

    $items = $list->items()->with('quest')->get();

    expect($items)->toHaveCount(count($names))
        ->and($items->pluck('quest.name')->all())->toBe($names)
        ->and($items->pluck('position')->all())->toBe(range(1, count($names)));
});

it('is idempotent', function () {
    (new QuestListSeeder)->run();
    $first = QuestList::firstWhere('name', 'Test Road to 95');
    $count = $first->items()->count();

    (new QuestListSeeder)->run();

    expect(QuestList::where('name', 'Test Road to 95')->count())->toBe(1)
        ->and($first->fresh()->items()->count())->toBe($count);
});

it('skips names missing from the catalog instead of aborting', function () {
    Quest::firstWhere('name', 'Maglious Demise')->delete();

    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context) => $context['missing'] === ['Maglious Demise'],
    );

    (new QuestListSeeder)->run();

    $list = QuestList::firstWhere('name', 'Test Road to 95');
    $names = json_decode(file_get_contents(database_path('data/quest_lists.json')), true)[0]['quests'];

    $items = $list->items()->with('quest')->get();

    expect($items)->toHaveCount(count($names) - 1)
        ->and($items->pluck('quest.name')->all())->not->toContain('Maglious Demise')
        ->and($items->pluck('position')->all())->toBe(range(1, count($names) - 1));
});
