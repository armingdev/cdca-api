<?php

namespace App\Game\Enums;

enum RunMode: string
{
    case Mob = 'mob';
    case Quest = 'quest';
    case QuestList = 'quest-list';
    case Pvp = 'pvp';
}
