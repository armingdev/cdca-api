<?php

namespace Database\Seeders;

use App\Game\Parsers\PotionEffectParser;
use App\Models\Potion;
use Illuminate\Database\Seeder;

/**
 * The 139-potion catalog harvested from the game's item database on
 * 2026-11-07 (database/data/potions.json). Idempotent: rows are keyed by the
 * game's item id, and re-seeding refreshes text and classification.
 */
class PotionSeeder extends Seeder
{
    public function run(PotionEffectParser $parser): void
    {
        $potions = json_decode(file_get_contents(database_path('data/potions.json')), true);
        $now = now();

        $rows = array_map(function (array $potion) use ($parser, $now): array {
            $effect = $parser->parse($potion['name'], $potion['description']);

            return [
                'game_item_id' => $potion['game_item_id'],
                'name' => $potion['name'],
                'image' => $potion['image'],
                'level' => $potion['level'],
                'player_bound' => $potion['player_bound'],
                'description' => $potion['description'],
                'kind' => $effect->kind->value,
                'duration_minutes' => $effect->durationMinutes,
                'rage_amount' => $effect->rageAmount,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $potions);

        Potion::query()->upsert(
            $rows,
            ['game_item_id'],
            ['name', 'image', 'level', 'player_bound', 'description', 'kind', 'duration_minutes', 'rage_amount', 'updated_at'],
        );
    }
}
