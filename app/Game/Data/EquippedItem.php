<?php

namespace App\Game\Data;

/**
 * One item worn on the equipment.php paper doll. The iid is the same instance
 * id item_rollover.php and backpack_action.php take, so a worn item can be
 * scored and unequipped without any further lookup.
 *
 * slotId is the doll's own numbering (see EquipmentSet::SLOT_NAMES) — a
 * different vocabulary from the tooltip's `[Slot - X]` label.
 */
final readonly class EquippedItem
{
    public function __construct(
        public int $iid,
        public string $name,
        public int $slotId,
    ) {}
}
