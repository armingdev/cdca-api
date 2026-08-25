<?php

namespace App\Game\Enums;

/**
 * Classified reasons a PvP attack POST came back 200 instead of 302.
 */
enum AttackRefusalReason: string
{
    /**
     * "You can only attack someone once every 60 minutes, and you attacked
     * this person {n} minutes ago." — VERIFIED 2026-08-22.
     */
    case Cooldown = 'cooldown';

    /**
     * The target is an ally, personally or through an allied crew — VERIFIED
     * 2026-08-25 from two live phrasings:
     *   "{Name} is your ally"
     *   "This player is a member of an Allied crew."
     *
     * Structural, not temporary: a crew hitlist is full of allies, so retrying
     * one every pass is pure waste. Blocked for a long window instead.
     */
    case Allied = 'allied';

    /**
     * "You can not attack this player at this time as they are under the
     * effects of PVP Immunity." — VERIFIED 2026-08-25. Temporary; the duration
     * is not stated, so it is re-checked on the normal cooldown cadence.
     */
    case PvpImmunity = 'pvp-immunity';

    /** The page bounced to /security_prompt — a secret-answer gate. */
    case SecurityPrompt = 'security-prompt';

    /** Target outside our level band, protected, or otherwise not attackable. */
    case NotAttackable = 'not-attackable';

    /** 200 with no recognised reason — capture the body and investigate. */
    case Unknown = 'unknown';

    /**
     * The base per-target cooldown. Time Warp (skill 3017, Affliction) allows
     * a second attack inside the same window, so this is a per-character
     * ceiling — never assume it globally.
     */
    public const int COOLDOWN_MINUTES = 60;

    /**
     * How long to stop trying this target after a refusal, or null when the
     * refusal says nothing about when it might succeed.
     *
     * Alliances change rarely, so a week keeps them out of the rotation
     * without pinning the decision forever.
     */
    public function blockMinutes(): ?int
    {
        return match ($this) {
            self::Cooldown => self::COOLDOWN_MINUTES,
            self::Allied => 60 * 24 * 7,
            self::PvpImmunity => self::COOLDOWN_MINUTES,
            default => null,
        };
    }
}
