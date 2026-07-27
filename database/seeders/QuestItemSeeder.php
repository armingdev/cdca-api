<?php

namespace Database\Seeders;

use App\Models\QuestItem;
use Illuminate\Database\Seeder;

/**
 * Imports the collect-item → source-mob catalog (database/data/
 * quest_items.json, from the xOWH seed's QuestItems). The seed contains a
 * few duplicate item names with differing mob lists; their mobs are merged.
 */
class QuestItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = json_decode(file_get_contents(database_path('data/quest_items.json')), true);

        $merged = [];

        foreach ($items as $item) {
            $name = trim($item['Name']);
            $mobs = array_map(trim(...), $item['Mobs']);
            $merged[$name] = array_values(array_unique(array_merge($merged[$name] ?? [], $mobs)));
        }

        foreach ($merged as $name => $mobs) {
            QuestItem::updateOrCreate(['name' => $name], ['source_mobs' => $mobs]);
        }
    }
}
