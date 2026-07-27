<?php

use App\Models\QuestItem;
use Database\Seeders\QuestItemSeeder;

it('seeds the collect-item catalog and merges duplicate names', function () {
    (new QuestItemSeeder)->run();

    // 3,146 seed rows, 17 duplicate names → 3,129 unique items.
    expect(QuestItem::count())->toBe(3129);

    expect(QuestItem::where('name', 'Holy Elemental Crystal')->first()->source_mobs)
        ->toBe(['Holy Elemental Keeper']);

    // Summoning Shard appears twice in the seed with different mob lists;
    // the seeder unions them.
    expect(QuestItem::where('name', 'Summoning Shard')->first()->source_mobs)
        ->toBe([
            'Withered Scamp', 'Horrific Scamp', 'Withered Ravager', 'Horrific Ravager',
            'Withered Bloodslave', 'Horrific Bloodslave',
            'Zhulian Horror', 'Zhulian Servant', 'Zhulian Shade', 'Zhulian Succubus',
        ]);
});
