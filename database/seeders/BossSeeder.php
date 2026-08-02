<?php

namespace Database\Seeders;

use App\Models\Boss;
use Illuminate\Database\Seeder;

/**
 * Imports the world-boss catalog (database/data/bosses.json, from the iowh
 * seed's Bosses file — note its lowercase keys). Ids are the game's boss ids.
 */
class BossSeeder extends Seeder
{
    public function run(): void
    {
        $bosses = json_decode(file_get_contents(database_path('data/bosses.json')), true);

        foreach ($bosses as $boss) {
            Boss::updateOrCreate(['id' => $boss['id']], [
                'name' => trim($boss['name']),
                'nick' => trim($boss['nick']),
                'rage_to_join' => $boss['rageToJoin'],
            ]);
        }
    }
}
