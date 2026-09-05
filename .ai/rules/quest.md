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

## The tracker, not the giver, says where a character stands in a quest
`world_questHelper.php` (parsed to `ActiveQuest` by `WorldQuestHelperParser`) lists every in-progress quest with its current step and that step's rows. A giver's `mob.php` popup lists a quest ONLY while its current step belongs to that mob, so a quest several steps in is silent at its original giver. Reading that silence as "completed" wrote 153 bogus `unavailable` ledger rows in one run.

So `QuestRunner` reads the tracker first every cycle: it farms unmet kill/collect rows straight off the tracker (no dialog needed — the mob will not talk until the counts are in), then follows the talk row (`Find X` / `Return to X`, which the game renders only once every count on the step is met) to the mob that actually holds the step. `QuestNotAvailableException` — the only path that writes `unavailable` — may be thrown ONLY when the quest is absent from the tracker AND no candidate mob offers it. A tracked quest nobody will open raises a plain `GameException` instead, so the list skips it without poisoning the ledger.

Resolve quest mobs by NAME (`navigateToNpc(string $npcName)`), never by `mobs.game_mob_id`: 184 seeded ids are shared by more than one mob (868 = Stella and Large Source Slime) and they do not always match the live world. Note the tracker's `mobid` is the mob id, while `mob.php`/`mob_talk.php` want the room blob's `spawnId`.
