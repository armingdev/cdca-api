<?php

namespace App\Game\World;

use App\Game\Data\RoomBlob;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Room;
use App\Models\WorldChange;

/**
 * The single funnel for every room blob any character ever loads — during
 * mapping or later farming. Upserts the room + mob sightings, stamps
 * verification, and journals topology drift, so the map keeps verifying and
 * extending itself at zero extra requests.
 */
class RoomObservationRecorder
{
    private const array TRACKED_FIELDS = ['name', 'north', 'east', 'south', 'west'];

    public function record(RoomBlob $blob, ?Character $character = null): Room
    {
        $attributes = [
            'name' => $blob->name,
            'north' => $blob->exits['north'] ?? null,
            'east' => $blob->exits['east'] ?? null,
            'south' => $blob->exits['south'] ?? null,
            'west' => $blob->exits['west'] ?? null,
            'doors' => $blob->doors,
            'is_gated' => false,
            'gate_reason' => null,
            'source' => 'spider',
            'last_verified_at' => now(),
        ];

        $room = Room::find($blob->curRoom);

        if ($room === null) {
            $room = Room::create(['id' => $blob->curRoom, 'first_seen_at' => now(), ...$attributes]);
        } else {
            $this->journalChanges($room, $attributes, $character);
            $room->update($attributes);
        }

        $this->recordMobs($blob, $room);

        return $room;
    }

    /**
     * A room we could not enter still exists — remember it as gated so the
     * spider never retries it, and journal if it was open before.
     */
    public function recordGated(int $roomId, string $reason, ?Character $character = null): Room
    {
        $room = Room::firstOrNew(['id' => $roomId]);

        if (! $room->exists) {
            $room->first_seen_at = now();
        } elseif (! $room->is_gated) {
            WorldChange::create([
                'room_id' => $roomId,
                'field' => 'is_gated',
                'old_value' => '0',
                'new_value' => '1',
                'character_id' => $character?->id,
                'observed_at' => now(),
            ]);
        }

        $room->is_gated = true;
        $room->gate_reason = $reason;
        $room->save();

        return $room;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function journalChanges(Room $room, array $attributes, ?Character $character): void
    {
        foreach (self::TRACKED_FIELDS as $field) {
            $old = $room->{$field};
            $new = $attributes[$field];

            if ($old !== $new) {
                WorldChange::create([
                    'room_id' => $room->id,
                    'field' => $field,
                    'old_value' => $old !== null ? (string) $old : null,
                    'new_value' => $new !== null ? (string) $new : null,
                    'character_id' => $character?->id,
                    'observed_at' => now(),
                ]);
            }
        }
    }

    private function recordMobs(RoomBlob $blob, Room $room): void
    {
        foreach ($blob->mobs as $sighting) {
            $mob = Mob::updateOrCreate(
                ['name' => $sighting->name],
                [
                    'game_mob_id' => $sighting->mobId > 0 ? $sighting->mobId : null,
                    'level' => $sighting->level,
                    'rage_cost' => $sighting->rageCost,
                    'type' => $sighting->type,
                    'can_form' => $sighting->canForm,
                    'image' => $sighting->image,
                    'last_seen_at' => now(),
                ],
            );

            $mob->rooms()->syncWithoutDetaching([$room->id => ['last_seen_at' => now()]]);
        }
    }
}
