<?php

namespace App\Game\Quest;

use App\Game\Enums\QuestObjectiveType;
use App\Models\BattleEvent;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\QuestItem;
use App\Models\Room;
use Illuminate\Support\Collection;

/**
 * Resolves everything location-shaped a quest detail page can show, in a
 * fixed number of batched queries: where the giver stands, where each
 * kill-condition mob roams, and where collect-condition items come from
 * (seeded source mobs ∪ drop-confirmed mobs ∪ the helper-verified target
 * room). All joins are by name — the only stable key across seed, crawl and
 * live world — and quietly return empty when the world map doesn't know a
 * name yet.
 */
class QuestLocationResolver
{
    private const int MAX_LOCATIONS_PER_MOB = 20;

    /**
     * @return array{
     *     giver: list<array<string, mixed>>,
     *     mobs: array<string, array<string, mixed>>,
     *     items: array<string, array<string, mixed>>
     * }
     */
    public function resolve(Quest $quest): array
    {
        $quest->loadMissing('steps.conditions');

        $conditions = $quest->steps->flatMap->conditions;
        $killTargets = $conditions->where('type', QuestObjectiveType::Kill)->pluck('target')->unique();
        $collectItems = $conditions->where('type', QuestObjectiveType::Collect)->pluck('target')->unique();

        $questItems = QuestItem::whereIn('name', $collectItems)
            ->with('targetRoom.area')
            ->get()
            ->keyBy('name');

        $observedSources = BattleEvent::query()
            ->whereIn('drop_name', $collectItems)
            ->whereNotNull('mob_id')
            ->distinct()
            ->get(['drop_name', 'mob_id'])
            ->groupBy('drop_name')
            ->map(fn (Collection $events) => $events->pluck('mob_id')->all());

        $sourceNames = $questItems->flatMap(fn (QuestItem $item) => $item->source_mobs ?? []);
        $wantedNames = $killTargets
            ->merge($sourceNames)
            ->when($quest->giver !== null, fn (Collection $names) => $names->push($quest->giver))
            ->unique()
            ->values();
        $wantedIds = $observedSources->flatten()->unique()->values();

        $mobs = Mob::query()
            ->where(fn ($query) => $query->whereIn('name', $wantedNames)->orWhereIn('id', $wantedIds))
            ->with('rooms.area')
            ->get();

        $mobsByName = $mobs->groupBy('name');
        $mobsById = $mobs->keyBy('id');

        // One representative mob per name, for the by-name lookups that only
        // need a single match. Reversed first, so that when a name repeats the
        // earliest mob wins the key — keyBy() otherwise keeps the last.
        $firstMobByName = $mobs->reverse()->keyBy('name');

        return [
            'giver' => $mobsByName->get($quest->giver, collect())
                ->flatMap(fn (Mob $mob) => $this->locations($mob->rooms))
                ->unique('room_id')
                ->values()
                ->all(),
            'mobs' => $killTargets
                ->mapWithKeys(fn (string $name) => [
                    $name => $this->mobInfo($mobsByName->get($name, collect())->first()),
                ])
                ->filter()
                ->all(),
            'items' => $collectItems
                ->mapWithKeys(fn (string $name) => [$name => $this->itemSources(
                    $questItems->get($name),
                    $mobsById->only($observedSources->get($name, []))->values(),
                    $firstMobByName,
                )])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Mob>  $confirmedMobs
     * @param  Collection<array-key, Mob>  $firstMobByName
     * @return array<string, mixed>
     */
    private function itemSources(?QuestItem $item, Collection $confirmedMobs, Collection $firstMobByName): array
    {
        $seeded = $this->mobsNamed($item->source_mobs ?? [], $firstMobByName);

        $confirmedIds = $confirmedMobs->pluck('id');

        $mobs = $confirmedMobs
            ->merge($seeded->reject(fn (Mob $mob) => $confirmedIds->contains($mob->id)))
            ->map(fn (Mob $mob) => [
                ...$this->mobDetails($mob),
                'confirmed_drop' => $confirmedIds->contains($mob->id),
            ])
            ->values()
            ->all();

        $targetRoom = $item?->targetRoom;

        return [
            'target_room' => $targetRoom !== null ? $this->location($targetRoom) : null,
            'helper_verified_at' => $item?->helper_verified_at,
            'mobs' => $mobs,
        ];
    }

    /**
     * The catalog mobs matching the given names, skipping names we have never
     * seen in the world (a seed list may name a mob that was never crawled).
     *
     * @param  list<string>  $names
     * @param  Collection<array-key, Mob>  $firstMobByName
     * @return Collection<int, Mob>
     */
    private function mobsNamed(array $names, Collection $firstMobByName): Collection
    {
        $found = [];

        foreach ($names as $name) {
            $mob = $firstMobByName->get($name);

            if ($mob instanceof Mob) {
                $found[] = $mob;
            }
        }

        return collect($found);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mobInfo(?Mob $mob): ?array
    {
        return $mob === null ? null : $this->mobDetails($mob);
    }

    /**
     * @return array<string, mixed>
     */
    private function mobDetails(Mob $mob): array
    {
        return [
            'id' => $mob->id,
            'name' => $mob->name,
            'level' => $mob->level,
            'rage_cost' => $mob->rage_cost,
            'room_count' => $mob->rooms->count(),
            'locations' => $this->locations($mob->rooms->take(self::MAX_LOCATIONS_PER_MOB)),
        ];
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @return list<array<string, mixed>>
     */
    private function locations(Collection $rooms): array
    {
        return $rooms->map(fn (Room $room) => $this->location($room))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function location(Room $room): array
    {
        return [
            'room_id' => $room->id,
            'room_name' => $room->name,
            'area' => $room->area?->name,
        ];
    }
}
