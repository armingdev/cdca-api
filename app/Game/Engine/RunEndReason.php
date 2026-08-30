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

    /**
     * The next target costs more rage than the character holds. Distinct from
     * RageExhausted (a configured floor): this one is the game's own price, so
     * no setting can lower it and the only cure is the hourly rage tick.
     */
    case RageInsufficient = 'rage_insufficient';

    /** No way to make progress: unfulfillable objective, unmapped giver, dead link. */
    case Stuck = 'stuck';

    /**
     * The pass reached the targets' spawn rooms and found none of them alive.
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

    /**
     * The quest wants an item the game only sells (a Quest Shard), so no
     * amount of farming can satisfy it. Skippable rather than stuck: the rest
     * of a list is still perfectly runnable.
     */
    case RequiresPurchasedItem = 'requires_purchased_item';

    /**
     * Something went wrong that is not a property of the quest: a page that
     * would not parse, an NPC not standing in its room this minute, a
     * momentarily unreachable path. Temporary — park and retry the same quest
     * a bounded number of times, because reading these as "skip" silently
     * dropped quests the character could perfectly well have completed.
     */
    case TransientError = 'transient_error';

    case ExternalStop = 'external_stop';
    case ExternalPause = 'external_pause';
}
