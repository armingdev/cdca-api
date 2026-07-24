<?php

namespace Database\Seeders;

use App\Models\JunkItem;
use Illuminate\Database\Seeder;

/**
 * Imports the junk-item name list (database/data/junk_items.json, from the
 * xOWH seed's JunkItems catalog). Matching is by name — the seed ids are the
 * bot's internal ids, not game item ids.
 */
class JunkItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = json_decode(file_get_contents(database_path('data/junk_items.json')), true);

        foreach ($items as $item) {
            JunkItem::updateOrCreate(['name' => trim($item['Name'])]);
        }
    }
}
