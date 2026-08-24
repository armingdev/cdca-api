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
}
