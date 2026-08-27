<?php

namespace Database\Seeders;

use App\Models\Quest;
use App\Models\QuestList;
use App\Models\QuestListItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Seeds the shared (user_id = null) quest lists from
 * database/data/quest_lists.json — currently "Test Road to 95", the wiki's
 * level 1-90 questing route, in order.
 *
 * The JSON holds quest *names*; they resolve against the catalog seeded by
 * QuestSeeder, so this must run after it. Names that no longer resolve are
 * skipped with a logged warning rather than aborting the seed — the catalog gets
 * corrected by the outwar:quests:map crawl, and a renamed quest shouldn't
 * take the whole route with it.
 *
 * Idempotent, and the JSON is authoritative: each run rewrites the list's
 * items to match the file exactly (order included).
 */
class QuestListSeeder extends Seeder
{
    public function run(): void
    {
        $lists = json_decode(file_get_contents(database_path('data/quest_lists.json')), true);

        $questIdsByName = Quest::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name) => [mb_strtolower(trim($name)) => $id]);

        foreach ($lists as $list) {
            $this->seedList($list['name'], $list['quests'], $questIdsByName);
        }
    }

    /**
     * @param  list<string>  $questNames
     * @param  Collection<string, int>  $questIdsByName
     */
    private function seedList(string $name, array $questNames, Collection $questIdsByName): void
    {
        $questList = QuestList::firstOrCreate(['name' => $name], ['user_id' => null]);

        $rows = [];
        $missing = [];

        foreach ($questNames as $questName) {
            $questId = $questIdsByName[mb_strtolower(trim($questName))] ?? null;

            if ($questId === null) {
                $missing[] = $questName;

                continue;
            }

            $rows[] = [
                'quest_list_id' => $questList->id,
                'position' => count($rows) + 1,
                'quest_id' => $questId,
                'label' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $questList->items()->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            QuestListItem::insert($chunk);
        }

        if ($missing !== []) {
            Log::warning('Quest list names missing from the catalog.', [
                'quest_list' => $name,
                'missing' => $missing,
            ]);
        }
    }
}
