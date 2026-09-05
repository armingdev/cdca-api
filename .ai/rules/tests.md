---
paths:
  - 'tests/**'
  - tests/Pest.php
---

# Tests

## Tests must never reach the live game servers, and shared helpers live in tests/Pest.php
`tests/Pest.php` calls `Http::preventStrayRequests()` in a global `beforeEach` for Feature tests: any request without a matching `Http::fake()` throws instead of hitting sigil/torax with a real session. When a new test drives engine code, fake every endpoint it touches (including indirect ones like `skills_info.php` behind the Circumspect gate).

The suite runs under `--parallel`, which shards by file. A helper function used by more than one test file MUST be defined in `tests/Pest.php` — defining it in a sibling test file works serially and fails in parallel with "Call to undefined function".

## Fake game pages must state, not stay silent
The engine now trusts the game's own answers over its local estimates, so an under-specified fake actively lies to the test. Two traps, both hit during the buff rework:

- `cast_skills.php` GET must return `fakeSkillsPageHtml()` (Current Effects built from real `last_cast_at`), not an arbitrary string. An empty panel means "no buffs active" and will expire every buff in the test.
- `skills_info.php` must return `fakeSkillInfoHtml($id)`, which emits the "recharging, N minutes remaining" line. A page that never mentions recharging tells the engine every cooldown has elapsed.
- `cast_skills.php` POST must echo the real skill name (`fakeCastConfirmationHtml`) — casts are name-matched now.

`fakeSkillsPageHtml` derives the window from `last_cast_at + duration`, never from `buff_until`: reading back our own derived column and rounding it nudges the expiry forward on every sync, producing a buff that can never lapse.

## Quest fakes must answer world_questHelper.php
Every fake driving `QuestRunner` must serve `world_questHelper.php`, because the runner now reads the tracker before it walks anywhere. A catch-all returning `<html>world</html>` throws `ParseException` and kills the run.

Build the body with `questHelperJson([...])` (live tracker markup). An empty list is the honest answer for a quest still sitting unaccepted at its giver — that is what `fakeQuestWorld`, `fakeMultiObjectiveQuestWorld` and `fakeLosingQuestWorld` return. A fake modelling a quest already under way must be stateful: `fakeMultiStepQuestWorld` lists the quest at step 2 only between its two turn-ins, and `fakeCollectQuestWorld` reports the live item count and adds the `Return to Rune Master` row once it is met. A static tracker that never advances makes the runner farm the same objective forever.
