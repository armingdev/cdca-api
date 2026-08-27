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

    /** No way to make progress: unfulfillable objective, unmapped giver, dead link. */
    case Stuck = 'stuck';

    /**
     * The objective's targets exist but every one of them was dead this pass.
     * Unlike Stuck this is temporary — they respawn on a timer — so parking
     * and retrying is productive rather than a dead end.
     */
    case TargetsDepleted = 'targets_depleted';

    /**
     * Smart mode gave up on the targets: the same mob won repeatedly even
     * after levelling and re-gearing, so we stop instead of feeding it rage.
     */
    case Outmatched = 'outmatched';

    /**
     * A Circumspect-gated run whose buff expired mid-pass. Jobs park until the
     * skill can be recast, then re-cast the run's skills and carry on.
     */
    case CircumspectExpired = 'circumspect_expired';

    case ExternalStop = 'external_stop';
    case ExternalPause = 'external_pause';
}
