<?php

namespace App\Game\Data;

/**
 * Parsed ajax/backpackcontents.php tab: the items plus the capacity footer
 * (#backpackmaxval). maxSlots is -1 when the tab is uncapped.
 */
final readonly class BackpackContents
{
    /**
     * @param  list<BackpackItem>  $items
     */
    public function __construct(
        public array $items,
        public int $maxSlots,
        public int $itemCount,
        public bool $isOver,
    ) {}

    /**
     * @return list<BackpackItem>
     */
    public function itemsNamed(string $name): array
    {
        return array_values(array_filter(
            $this->items,
            fn (BackpackItem $item): bool => strcasecmp($item->name, $name) === 0,
        ));
    }
}
