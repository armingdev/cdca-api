<?php

namespace App\Game\Enums;

/**
 * The game's own verdict on whether a target is within our level band,
 * rendered as the colour of the level cell on every hitlist page.
 *
 * Preferred over a computed level rule — the game states it directly.
 */
enum TargetAttackability: string
{
    case TooWeak = 'too-weak';
    case InRange = 'in-range';
    case TooPowerful = 'too-powerful';

    /** No colour on the cell (crew rosters, brawl standings) — unknown. */
    case Unknown = 'unknown';

    /** Map a level cell's `<font color="…">` to the game's verdict. */
    public static function fromColor(?string $color): self
    {
        return match (strtoupper((string) $color)) {
            '#00FF00' => self::TooWeak,
            '#00FFFF' => self::InRange,
            '#FF0000' => self::TooPowerful,
            default => self::Unknown,
        };
    }

    /**
     * Whether an attack is worth spending a request on. Unknown counts as
     * attackable: rosters and standings carry no colour, and the attack
     * itself is the cheapest way to find out.
     */
    public function isWorthAttacking(): bool
    {
        return $this !== self::TooWeak && $this !== self::TooPowerful;
    }
}
