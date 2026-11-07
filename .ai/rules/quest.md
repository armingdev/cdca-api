---
paths:
  - 'app/Game/Quest/**'
---

# Quest

## Skips must distinguish "cannot" from "not right now"
Before any of these, QuestListRunner **defers** what the character is not ready for — never walks to it, never skips it: `required_level` above the character (after `tryLevelUp()` when `level_up`/`smart` allows), or a `prerequisite_quest_id` deferred earlier in the pass. A giver is silent below a quest's level exactly as for a finished quest; reading that silence as a skip is what made a level-83 fleet "complete" an 80-95 list with 0 quests done (prod run 107, Nov 2026). When a pass ends and the character has grown into a deferred quest, the runner replays the lists playing only the deferred quests (`pass`/`retrying` in `QuestListProgress`).

Then it catches three tiers, and collapsing them re-introduces the bug where quests were silently dropped:
- `TransientGameException`/`ParseException` → `retryOrSkip()`: parks with `RunEndReason::TransientError`, budget `MAX_QUEST_RETRIES` carried in `progress['quest_retries']`. A bad page read or an NPC not rendered this minute is not a verdict on the quest.
- other `GameException` (unmapped giver, no path, a gate the character was turned away from) → permanent skip.
- `QuestNotAvailableException` → skip AND `QuestProgressLedger::recordUnavailable`.

All list state — cursor, pass, deferred set, run-wide tallies — lives in `QuestListProgress` and is persisted on every settle and every park; the job must write `$summary->progress->toArray()`, never hand-picked keys, or a resume loses the pass. `QuestListRunSummary::$questsCompleted`/`$questsSkipped` stay per invocation on purpose: the job's "made progress" checks reset park budgets on them, and run-wide numbers there would keep a dead run parked forever.

Also in QuestRunner: never take `unmetObjectives()[0]` — loop all of them (a step can want two different mobs), and never treat a turn-in with no continue link as "quest complete" without `reEnterUnfinishedQuest()` asking the giver first. Both were real wrong-skip paths.

`QuestProgressLedger` is what stops a 200-quest list re-walking to every finished giver; `unavailable` is an inference: never written or trusted for a character below the quest's level (its `context.level` records the level at the time), cleared by `recordCompleted()` of its prerequisite, and clearable via the quest-progress endpoints.

## The tracker, not the giver, says where a character stands in a quest
`world_questHelper.php` (parsed to `ActiveQuest` by `WorldQuestHelperParser`) lists every in-progress quest with its current step and that step's rows. A giver's `mob.php` popup lists a quest ONLY while its current step belongs to that mob, so a quest several steps in is silent at its original giver. Reading that silence as "completed" wrote 153 bogus `unavailable` ledger rows in one run.

So `QuestRunner` reads the tracker first every cycle: it farms unmet kill/collect rows straight off the tracker (no dialog needed — the mob will not talk until the counts are in), then follows the talk row (`Find X` / `Return to X`, which the game renders only once every count on the step is met) to the mob that actually holds the step. `QuestNotAvailableException` — the only path that writes `unavailable` — may be thrown ONLY when the quest is absent from the tracker AND no candidate mob offers it. A tracked quest nobody will open raises a plain `GameException` instead, so the list skips it without poisoning the ledger.

Resolve quest mobs by NAME (`navigateToNpc(string $npcName)`), never by `mobs.game_mob_id`: 184 seeded ids are shared by more than one mob (868 = Stella and Large Source Slime) and they do not always match the live world. Note the tracker's `mobid` is the mob id, while `mob.php`/`mob_talk.php` want the room blob's `spawnId`.
