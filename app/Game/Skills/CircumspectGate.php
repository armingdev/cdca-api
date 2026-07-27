<?php

namespace App\Game\Skills;

use App\Game\Exceptions\GameException;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\Skill;
use Carbon\CarbonInterface;

/**
 * Computes when a Circumspect-gated participant should wake up: at the end
 * of Circumspect's cooldown (server-read recharge when known, last cast +
 * level-scaled cooldown otherwise) plus a small buffer for clock skew and
 * the game's minute-granular "recharging" reporting. When no cooldown end
 * is known — or it is already past yet the gate still failed (e.g. rage too
 * low to pay the cast cost) — fall back to a retry floor so the scheduler
 * never hot-loops a participant.
 */
class CircumspectGate
{
    private const int BUFFER_MINUTES = 2;

    private const int RETRY_FLOOR_MINUTES = 30;

    /**
     * @param  bool  $refresh  read the authoritative recharge from the game first
     *                         (one skills_info.php request; failures fall back to local state)
     */
    public function resumeAtFor(Character $character, bool $refresh = false): CarbonInterface
    {
        $circumspect = Skill::find(Skill::CIRCUMSPECT_ID);

        if ($refresh && $circumspect !== null) {
            try {
                SkillSyncService::forCharacter($character)->refreshSkillInfo($circumspect);
            } catch (GameException) {
                // Local cast bookkeeping is a good enough estimate.
            }
        }

        $state = CharacterSkill::with('skill')
            ->where('character_id', $character->id)
            ->where('skill_id', Skill::CIRCUMSPECT_ID)
            ->first();

        $cooldownEndsAt = $state?->cooldownEndsAt();

        if ($cooldownEndsAt !== null && $cooldownEndsAt->isFuture()) {
            return $cooldownEndsAt->copy()->addMinutes(self::BUFFER_MINUTES);
        }

        return now()->addMinutes(self::RETRY_FLOOR_MINUTES);
    }
}
