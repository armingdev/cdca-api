<?php

namespace App\Game\Enums;

enum BattleOutcome: string
{
    case Win = 'win';
    case Loss = 'loss';

    /** The attack never happened (stale encid, contention, out of rage…). */
    case Failed = 'failed';

    /** A battle page we could not classify — capture and investigate. */
    case Unknown = 'unknown';
}
