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

    /**
     * Not a user signal: a Circumspect-gated run whose buff has just run out.
     * The engine ends its pass immediately so the job can park until the skill
     * comes off cooldown rather than fight on at full rage cost.
     */
    case CircumspectExpired = 'circumspect_expired';

    /**
     * Not a user signal either: the queue worker driving this run was told to
     * quit (deploy, horizon:terminate, a restart). The engine ends its pass at
     * the next check so the job can park and the worker can exit in seconds
     * instead of holding the shutdown for the rest of a two-hour run.
     */
    case WorkerShutdown = 'worker_shutdown';
}
