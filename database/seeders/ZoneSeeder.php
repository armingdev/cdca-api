<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * Imports the zone catalog (database/data/zones.json, from the iowh seed's
 * Zones file — cleaned of its trailing commas at copy time so it decodes
 * strictly) and stamps zone_id onto areas, joined by area name (the seed's
 * zone→area name join is exact: 462/462 match). Zone 0 "Deleted" groups
 * areas removed from the live game. Must run after AreaSeeder.
 */
class ZoneSeeder extends Seeder
{
    public function run(): void
    {
        $zones = json_decode(file_get_contents(database_path('data/zones.json')), true);

        foreach ($zones as $zone) {
            Zone::updateOrCreate(['id' => $zone['Id']], ['name' => trim($zone['Name'])]);

            Area::whereIn('name', array_map(trim(...), $zone['Areas']))->update(['zone_id' => $zone['Id']]);
        }
    }
}
