<?php

namespace App\Game\Data;

/**
 * One item in an ajax/backpackcontents.php tab. The list HTML carries the
 * name (`data-name`) and stack size (`data-itemidqty`) directly; stats
 * require a follow-up item_rollover.php call (see ItemDetail).
 *
 * `iid` (`data-iid`) is the per-character *instance* id — the value actions
 * take. `gameItemId` (`data-itemid`) is the catalog id, stable across
 * characters, so it is the key to store anything about the item itself.
 *
 * menuFlags is the makemenu() capability string selecting the context-menu
 * actions (e.g. `edzcvs`, `dzcv`, `acvs`); `e` = equippable, `a` =
 * activatable (teleport items). Equipped gear is NOT part of the backpack
 * list — it lives on equipment.php.
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
        public ?int $gameItemId = null,
    ) {}

    public function canEquip(): bool
    {
        return str_contains($this->menuFlags, 'e');
    }

    /**
     * Offered an "activate" action — in practice a teleport item. The key tab
     * mixes these with carry-only gating keys (`cvs`), which unlock rooms just
     * by being in the backpack and are never activated.
     */
    public function canActivate(): bool
    {
        return str_contains($this->menuFlags, 'a');
    }
}
