<?php

namespace App\Game\Data;

/**
 * One item in an ajax/backpackcontents.php tab. The list HTML carries the
 * name (`data-name`) and stack size (`data-itemidqty`) directly; stats
 * require a follow-up item_rollover.php call (see ItemDetail).
 *
 * menuFlags is the makemenu() capability string selecting the context-menu
 * actions (e.g. `edzcvs`, `dzcv`); `e` = the item is equippable. Equipped
 * gear is NOT part of the backpack list — it lives on equipment.php.
 */
final readonly class BackpackItem
{
    public function __construct(
        public int $iid,
        public string $name,
        public int $qty,
        public int $slotIndex,
        public int $ownerId,
        public string $menuFlags,
        public int $equipSlotType,
        public string $image,
    ) {}

    public function canEquip(): bool
    {
        return str_contains($this->menuFlags, 'e');
    }
}
