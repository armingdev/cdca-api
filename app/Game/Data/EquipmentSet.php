<?php

namespace App\Game\Data;

/**
 * Everything a character is wearing, keyed by the doll's slot id. Slots hold a
 * list because slot 8 stacks several orbs; the wearable-gear slots hold one.
 *
 * A slot missing from the map is empty — the page renders an empty div and
 * carries no slot marker at all for it (VERIFIED 2026-08-09 on a naked
 * character).
 */
final readonly class EquipmentSet
{
    /**
     * Doll slot id → the tooltip's `[Slot - X]` label, for the slots that
     * hold ordinary gear (VERIFIED 2026-08-09). Slots outside this map are
     * orbs (8), the accessory/rune/badge/crest/gem slots (10–17) — none of
     * which auto-equip scoring handles.
     */
    public const array SLOT_NAMES = [
        0 => 'Chest',
        1 => 'Shield',
        2 => 'Boots',
        3 => 'Weapon',
        4 => 'Ring',
        5 => 'Head',
        6 => 'Neck',
        7 => 'Belt',
        9 => 'Pants',
    ];

    /**
     * @param  array<int, list<EquippedItem>>  $slots
     */
    public function __construct(
        public array $slots,
    ) {}

    /**
     * @return list<EquippedItem>
     */
    public function itemsIn(int $slotId): array
    {
        return $this->slots[$slotId] ?? [];
    }

    /**
     * The items worn in the slot the tooltip calls $slotName; empty for slots
     * outside SLOT_NAMES.
     *
     * @return list<EquippedItem>
     */
    public function itemsInSlotNamed(string $slotName): array
    {
        $slotId = array_search($slotName, self::SLOT_NAMES, true);

        return $slotId === false ? [] : $this->itemsIn($slotId);
    }

    public function isEmpty(int $slotId): bool
    {
        return $this->itemsIn($slotId) === [];
    }

    /**
     * @return list<EquippedItem>
     */
    public function all(): array
    {
        return array_merge([], ...array_values($this->slots));
    }
}
