<?php

namespace Database\Seeders;

use App\Models\Mob;
use App\Models\Room;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Imports the mob catalog and its room placements (database/data/mobs.json,
 * from the xowh seed's Mobs file). The seed mixes native and string-encoded
 * types ("3873", "true"/"True"), so every value is normalized first. Name is
 * the identity key: duplicate names are merged (flags OR'd, rooms unioned)
 * and the seed's Id becomes game_mob_id only when it is plausible and claimed
 * by a single name (1,233 mobs ship Id 0, 17 share 9999). On re-runs only the
 * seed-owned flag columns update — level, type, game_mob_id and the pivot's
 * last_seen_at stay owned by the live recorder.
 */
class MobSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/mobs.json')), true);

        $mobs = [];

        foreach ($rows as $row) {
            $name = trim($row['Name']);

            $normalized = [
                'name' => $name,
                'seed_id' => (int) $row['Id'],
                'level' => (int) $row['Level'],
                'raid' => $this->bool($row['Raid'] ?? false),
                'attackable' => $this->bool($row['Attackable'] ?? false),
                'talkable' => $this->bool($row['Talkable'] ?? false),
                'spawnable' => $this->bool($row['Spawnable'] ?? false),
                'trainer' => $this->bool($row['Trainer'] ?? false),
                'long_respawn' => $this->bool($row['LongRespawn'] ?? false),
                'spawn_count' => (int) $row['Num'],
                'rooms' => $row['Rooms'],
            ];

            if (! isset($mobs[$name])) {
                $mobs[$name] = $normalized;

                continue;
            }

            foreach (['raid', 'attackable', 'talkable', 'spawnable', 'trainer', 'long_respawn'] as $flag) {
                $mobs[$name][$flag] = $mobs[$name][$flag] || $normalized[$flag];
            }

            $mobs[$name]['rooms'] = array_values(array_unique(array_merge($mobs[$name]['rooms'], $normalized['rooms'])));
        }

        $idClaims = array_count_values(array_column($mobs, 'seed_id'));

        $records = [];

        foreach ($mobs as $mob) {
            $seedId = $mob['seed_id'];
            $usableId = $seedId > 0 && $seedId !== 9999 && $seedId < 1_000_000 && $idClaims[$seedId] === 1;

            $records[] = [
                'name' => $mob['name'],
                'game_mob_id' => $usableId ? $seedId : null,
                'level' => $mob['level'] ?: null,
                'type' => $mob['raid'] ? 1 : 0,
                'can_form' => false,
                'attackable' => $mob['attackable'],
                'talkable' => $mob['talkable'],
                'spawnable' => $mob['spawnable'],
                'trainer' => $mob['trainer'],
                'long_respawn' => $mob['long_respawn'],
                'spawn_count' => $mob['spawn_count'],
            ];
        }

        foreach (array_chunk($records, 1000) as $chunk) {
            Mob::upsert($chunk, ['name'], [
                'attackable', 'talkable', 'spawnable', 'trainer', 'long_respawn', 'spawn_count',
            ]);
        }

        $this->seedPlacements($mobs);
    }

    /**
     * Bulk-attach mob→room placements, skipping rooms absent from the seeded
     * graph (the pivot's FK would abort) and pairs the spider already
     * recorded.
     *
     * @param  array<string, array<string, mixed>>  $mobs
     */
    private function seedPlacements(array $mobs): void
    {
        $mobIds = Mob::pluck('id', 'name');
        $roomIds = Room::pluck('id')->flip();

        $pairs = [];

        foreach ($mobs as $mob) {
            $mobId = $mobIds[$mob['name']];

            foreach ($mob['rooms'] as $roomId) {
                if (isset($roomIds[$roomId])) {
                    $pairs[] = ['mob_id' => $mobId, 'room_id' => $roomId];
                }
            }
        }

        foreach (array_chunk($pairs, 5000) as $chunk) {
            DB::table('mob_room')->insertOrIgnore($chunk);
        }
    }

    private function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : strtolower(trim((string) $value)) === 'true';
    }
}
