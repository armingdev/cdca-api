<?php

namespace App\Game\Engine;

/**
 * Why an engine loop returned. Jobs map these to participant statuses; the
 * engines themselves never decide statuses, so the mapping (and its policy,
 * like "rage-out waits for Circumspect") lives in exactly one place per mode.
 */
enum RunEndReason: string
{
    /** The mode's goal is done: quest/list complete, no live targets remain. */
    case Completed = 'completed';

    /** A configured cap was reached: max_kills, run_count, attacks per target. */
    case TargetReached = 'target_reached';

    /** Rage fell below the stop floor and could not be recovered. */
    case RageExhausted = 'rage_exhausted';

    /** A pass finished and the configured attack interval should elapse before the next. */
    case IntervalWait = 'interval_wait';

    /** No way to make progress: unfulfillable objective, unmapped giver, dead link. */
    case Stuck = 'stuck';

    case ExternalStop = 'external_stop';
    case ExternalPause = 'external_pause';
}
