<?php

namespace Database\Seeders;

use App\Game\Enums\TeleportKind;
use App\Game\World\TeleportService;
use App\Models\TeleportAnchor;
use Illuminate\Database\Seeder;

/**
 * Preloads the teleport catalog from the 2026-08-16 capture (28 item anchors
 * with their observed landing rooms + the 17 Teleport-skill destinations).
 *
 * This is the catalog half only — it never says a *character* holds any of
 * them, because that depends on level and quest progression. Per-character
 * availability always comes from TeleportService::syncAnchors().
 *
 * Runs after SkillSeeder (skill destinations reference skills.id).
 *
 * Idempotent, and never clobbers a live observation: rows already marked
 * `source = observed` keep their landing room and description.
 */
class TeleportAnchorSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('data/teleport_anchors.json')), true);

        foreach ($data['items'] as $item) {
            $this->upsert(
                [
                    'kind' => TeleportKind::Item,
                    'game_item_id' => $item['game_item_id'],
                ],
                [
                    'name' => $item['name'],
                    'room_id' => $item['room_id'],
                    'required_level' => $item['required_level'],
                    'rage_cost' => 0,
                    'cooldown_minutes' => 0,
                    'description' => $item['description'],
                ],
            );
        }

        foreach ($data['skill_destinations'] as $destination) {
            $this->upsert(
                [
                    'kind' => TeleportKind::Skill,
                    'skill_id' => TeleportService::TELEPORT_SKILL_ID,
                    'room_id' => $destination['room_id'],
                ],
                [
                    'name' => $destination['name'],
                    'rage_cost' => 100,
                    'cooldown_minutes' => 60,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array $key, array $attributes): void
    {
        $existing = TeleportAnchor::query()->firstWhere($key);

        if ($existing !== null) {
            if ($existing->source === 'observed') {
                return;
            }

            $existing->update([...$attributes, 'source' => 'capture']);

            return;
        }

        TeleportAnchor::create([
            ...$key,
            ...$attributes,
            'source' => 'capture',
            'first_seen_at' => now(),
        ]);
    }
}
