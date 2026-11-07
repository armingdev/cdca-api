<?php

namespace App\Game\Enums;

/**
 * The kind of decision a run event records. Events are the durable audit
 * trail for choices the engine makes on its own — which skills it cast or
 * skipped and why, which quests it walked past, why a participant parked.
 * Per-iteration chatter stays in the participant's last_activity line.
 */
enum RunEventType: string
{
    case SkillCast = 'skill_cast';
    case SkillCastFailed = 'skill_cast_failed';
    case SkillSkipped = 'skill_skipped';
    case SkillSyncFailed = 'skill_sync_failed';

    /** A buff potion kept active, or a rage potion drunk instead of waiting for rage. */
    case PotionUsed = 'potion_used';

    /** A selected potion that could not be used (none left, refused). */
    case PotionSkipped = 'potion_skipped';

    case QuestStarted = 'quest_started';
    case QuestCompleted = 'quest_completed';
    case QuestSkipped = 'quest_skipped';
    case QuestRetryScheduled = 'quest_retry_scheduled';
    case ObjectiveProgress = 'objective_progress';

    case Parked = 'parked';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Info = 'info';
}
