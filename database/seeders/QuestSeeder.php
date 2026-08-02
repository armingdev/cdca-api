<?php

namespace Database\Seeders;

use App\Models\Quest;
use Illuminate\Database\Seeder;

/**
 * Imports the quest catalog metadata (database/data/quests.json, from the
 * xowh seed's Quests file). The seed reuses 131 ids for different quests
 * while game_quest_id is unique — the first occurrence wins and the
 * authoritative outwar:quests:map crawl corrects the losers later. Quests
 * already crawled (last_mapped_at set) are skipped entirely: the crawl's
 * data is richer. Step content is never in the seed; only numberOfSteps.
 */
class QuestSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/quests.json')), true);

        $mapped = Quest::whereNotNull('last_mapped_at')->pluck('game_quest_id')->flip();

        $records = [];

        foreach ($rows as $row) {
            $id = $row['Id'];

            if (isset($records[$id]) || isset($mapped[$id])) {
                continue;
            }

            $records[$id] = [
                'game_quest_id' => $id,
                'name' => trim($row['Name']),
                'giver' => trim($row['Giver'] ?? '') ?: null,
                'required_level' => $row['LevelReq'],
                'prerequisite' => $this->prerequisite($row['Prerequisite'] ?? null),
                'steps_count' => $row['numberOfSteps'],
                'total_exp' => $row['TotalExp'],
                'item_rewards' => $this->itemRewards($row['ItemRewards'] ?? null),
                'repeatable' => (bool) $row['Repeatable'],
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            Quest::upsert($chunk, ['game_quest_id'], [
                'name', 'giver', 'required_level', 'prerequisite',
                'steps_count', 'total_exp', 'item_rewards', 'repeatable',
            ]);
        }
    }

    /**
     * The seed writes "Unknown"/"None" for most quests — only a real quest
     * name is worth keeping.
     */
    private function prerequisite(?string $value): ?string
    {
        $value = trim((string) $value);

        return in_array(strtolower($value), ['', 'unknown', 'none'], true) ? null : $value;
    }

    /**
     * Splits the seed's comma-joined reward string. Encoded manually because
     * upsert() bypasses the model's array cast.
     */
    private function itemRewards(?string $value): ?string
    {
        $items = array_values(array_filter(array_map(trim(...), explode(',', (string) $value))));

        return $items === [] ? null : json_encode($items);
    }
}
