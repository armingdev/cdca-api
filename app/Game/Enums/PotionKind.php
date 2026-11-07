<?php

namespace App\Game\Enums;

/**
 * What a potion does, as far as a run is concerned.
 */
enum PotionKind: string
{
    /** Lasts a while (ATK/HP/crit/elemental/exp…): keep it active before combat. */
    case Buff = 'buff';

    /** Instant rage: drink it instead of parking for the hourly rage tick. */
    case Rage = 'rage';

    /** Quest items, disguises, PvP immunity, novelty — never used by a run. */
    case Other = 'other';
}
