<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Room;
use Illuminate\Database\Seeder;

/**
 * Imports the named world regions (database/data/areas.json, from the xowh
 * seed's Areas catalog) and stamps area_id onto the rooms we have mapped.
 * Re-runnable: rooms spidered after seeding pick their area up on the next
 * run.
 */
class AreaSeeder extends Seeder
{
    public function run(): void
    {
        $areas = json_decode(file_get_contents(database_path('data/areas.json')), true);

        foreach ($areas as $area) {
            Area::updateOrCreate(['id' => $area['Id']], ['name' => trim($area['Name'])]);

            Room::whereIn('id', $area['Rooms'])->update(['area_id' => $area['Id']]);
        }
    }
}
