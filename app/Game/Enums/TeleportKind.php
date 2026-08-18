<?php

namespace App\Game\Enums;

/**
 * How a teleport is performed. Only these two are catalogued as anchors — the
 * home tavern and the room-1 hatch are per-character/always-available edges
 * the planner adds itself.
 */
enum TeleportKind: string
{
    /** A key-tab item with the `a` (activate) menu flag: free, no cooldown, not consumed. */
    case Item = 'item';

    /** The Teleport skill (id 27): 100 rage, 60 min cooldown, must be trained. */
    case Skill = 'skill';
}
