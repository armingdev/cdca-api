<?php

namespace App\Game\Enums;

/**
 * What a character has settled with the game about one quest.
 */
enum QuestProgressStatus: string
{
    /** The engine ran the quest through to its end. */
    case Completed = 'completed';

    /**
     * The giver did not offer it. Usually "already done", but the game gives
     * the same silence for prerequisites not yet met, so this verdict is
     * clearable rather than final.
     */
    case Unavailable = 'unavailable';
}
