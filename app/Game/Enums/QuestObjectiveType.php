<?php

namespace App\Game\Enums;

enum QuestObjectiveType: string
{
    /** Kill a named mob N times ("{Name}: n/m killed"). */
    case Kill = 'kill';

    /** Collect an item N times by farming drops ("{Item}: n/m"). */
    case Collect = 'collect';

    /** Talk to a named NPC. */
    case Talk = 'talk';
}
