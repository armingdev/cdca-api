---
paths:
  - 'app/Game/Quest/**'
---

# Quest

## Skips must distinguish "cannot" from "not right now"
QuestListRunner catches three tiers, and collapsing them re-introduces the bug where quests were silently dropped:
- `TransientGameException`/`ParseException` → `retryOrSkip()`: parks with `RunEndReason::TransientError`, budget `MAX_QUEST_RETRIES` carried in `progress['quest_retries']`. A bad page read or an NPC not rendered this minute is not a verdict on the quest.
- other `GameException` (unmapped giver, no path) → permanent skip.
- `QuestNotAvailableException` → skip AND `QuestProgressLedger::recordUnavailable`.

Also in QuestRunner: never take `unmetObjectives()[0]` — loop all of them (a step can want two different mobs), and never treat a turn-in with no continue link as "quest complete" without `reEnterUnfinishedQuest()` asking the giver first. Both were real wrong-skip paths.

`QuestProgressLedger` is what stops a 200-quest list re-walking to every finished giver; `unavailable` is an inference and must stay clearable via the quest-progress endpoints.
