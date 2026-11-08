<?php

namespace App\Game\World;

use App\Game\Exceptions\GatedRoomException;
use App\Models\Character;
use Illuminate\Support\Facades\Cache;

/**
 * Rooms the game has turned one character away from ("you must be carrying
 * Triworld Teleporter"), so path planning routes around them instead of
 * walking back to be refused again — once per quest, 34 quests in a row, was
 * the shape of the Nov 2026 Triworld reports.
 *
 * Per character, because whether a gate opens depends on what that character
 * carries (the world-level fact lives on rooms.is_gated). Soft state with a
 * TTL: losing it costs one wasted walk, and finishing a quest — which may hand
 * out the very key — forgets it so the gate gets another try.
 */
class GatedRooms
{
    private const int REMEMBER_HOURS = 24;

    public function remember(Character $character, GatedRoomException $gate): void
    {
        $rooms = $this->for($character);
        $rooms[$gate->roomId] = $gate->requiredItem() ?? $gate->reason;

        Cache::put($this->key($character), $rooms, now()->addHours(self::REMEMBER_HOURS));
    }

    /**
     * @return array<int, string> room id => what entering it wants
     */
    public function for(Character $character): array
    {
        return Cache::get($this->key($character), []);
    }

    public function forget(Character $character): void
    {
        Cache::forget($this->key($character));
    }

    private function key(Character $character): string
    {
        return "character:{$character->id}:gated-rooms";
    }
}
