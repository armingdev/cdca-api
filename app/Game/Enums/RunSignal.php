<?php

namespace App\Game\Enums;

/**
 * External control signal for a live run, delivered to engine loops through
 * the cache (see Run::signal()) so per-iteration checks stay cheap. The DB
 * participant statuses remain the source of truth; the cache is only the
 * fast path.
 */
enum RunSignal: string
{
    case None = 'none';
    case Pause = 'pause';
    case Stop = 'stop';
}
