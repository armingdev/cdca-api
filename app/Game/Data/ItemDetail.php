<?php

namespace App\Game\Data;

/**
 * Parsed item_rollover.php tooltip: name, equip slot, level gate, and the
 * stat bonuses.
 *
 * Stat keys are the lowercased labels as printed by the game (`atk`, `hp`,
 * `block`, `elemental block`, `critical hit`, `holy`, `arcane`, `fire`,
 * `kinetic`, `shadow`, `holy resist`, `rampage`, `rage per hr`,
 * `exp per hr`, `max rage`, …). Percentage stats store the bare number, and
 * enhancement bonuses (`+550 (+3) HP`) are summed into the value.
 */
final readonly class ItemDetail
{
    /**
     * @param  array<string, int>  $stats
     */
    public function __construct(
        public string $name,
        public ?string $slot,
        public ?int $requiredLevel,
        public array $stats,
        public ?int $tradesLeftToday,
        /** The tooltip's italic "click to activate" marker — a usable item. */
        public bool $activatable = false,
        /** Flavour/effect prose, e.g. "Teleports you to the Plane of Fire." */
        public ?string $description = null,
    ) {}

    public function stat(string $name): int
    {
        return $this->stats[strtolower($name)] ?? 0;
    }
}
