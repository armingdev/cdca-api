<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

/**
 * Imports the full room graph (database/data/rooms.json, from the xowh seed's
 * Rooms catalog, 41k rooms). Exit value 0 means "no exit" and becomes null;
 * exits pointing at rooms absent from the seed are kept as-is (the graph is
 * directed and unconstrained — the spider verifies live). The seed contains
 * 30 duplicate ids with conflicting rows; the row with a name and more exits
 * wins. Rooms the spider has already visited (source = 'spider') are never
 * overwritten — live data is fresher.
 */
class RoomSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/rooms.json')), true);

        $byId = [];

        foreach ($rows as $row) {
            $id = $row['Id'];
            $byId[$id] = isset($byId[$id]) ? $this->better($byId[$id], $row) : $row;
        }

        $spidered = Room::where('source', 'spider')->pluck('id')->flip();

        $records = [];

        foreach ($byId as $row) {
            if (isset($spidered[$row['Id']])) {
                continue;
            }

            $records[] = [
                'id' => $row['Id'],
                'name' => trim($row['Name']) !== '' ? trim($row['Name']) : null,
                'north' => $row['North'] ?: null,
                'east' => $row['East'] ?: null,
                'south' => $row['South'] ?: null,
                'west' => $row['West'] ?: null,
                'source' => 'seed',
            ];
        }

        foreach (array_chunk($records, 2000) as $chunk) {
            Room::upsert($chunk, ['id'], ['name', 'north', 'east', 'south', 'west', 'source']);
        }
    }

    /**
     * Pick the more informative of two seed rows sharing an id: a non-empty
     * name outweighs exits, first row wins ties.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function better(array $a, array $b): array
    {
        return $this->score($b) > $this->score($a) ? $b : $a;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function score(array $row): int
    {
        $hasName = trim($row['Name']) !== '' ? 2 : 0;
        $hasExit = ($row['North'] || $row['East'] || $row['South'] || $row['West']) ? 1 : 0;

        return $hasName + $hasExit;
    }
}
