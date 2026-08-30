# API Changes — Just-in-Time Buffs, Quest Memory & the Run Log (2026-08-30)

Audience: the Angular client repo. This is the client-facing half of a
five-phase backend change driven by player feedback: skills casting
unreliably, quest lists restarting from quest #1 every run, quests being
skipped that were not actually finished, and no way to find out what happened
after the fact.

Everything here is additive except item 1, which is a **BREAKING response
shape change**.

## TL;DR for the client

- `POST /characters/{id}/cast` with `on_start: true` now returns a per-skill
  report instead of a count. **Breaking** — see §1.
- `cast_on_start` on a run no longer means "cast at run start". It now means
  "keep the selected set active", and the casting happens right before combat.
  Copy change only, no payload change — see §2.
- New: `GET /runs/{run}/events`, the durable run log. This is the answer to
  "which quests did it skip, and why" — see §4.
- New: quest-progress endpoints, including the two "clear the cache" actions
  the player asked for — see §5.
- Signing in to CDCA now syncs characters in the background — see §6.

---

## 1. BREAKING — `POST /api/v1/characters/{character}/cast` with `on_start`

The set-casting path used to decide everything from stale local state, throw
away the per-skill reasons, and answer with a bare count. That count was
exactly what hid the reported bug: "Cast 5 skill(s)" when nine were selected,
with no hint that four had been skipped or why.

It now reads the character's live skill state from the game first, then
reports every skill.

**Before**

```json
{ "message": "Cast 5 skill(s).", "cast": 5 }
```

**After**

```json
{
  "message": "Cast 7 skill(s).",
  "cast":    [{ "skill_id": 3, "name": "Empower" }],
  "skipped": [{ "skill_id": 7, "name": "On Guard", "reason": "untrained" }],
  "failed":  [{ "skill_id": 4, "name": "Stealth",  "reason": "refused" }]
}
```

`reason` is one of `active` (buff already up), `cooldown`, `untrained`,
`rage` (the skill costs more than the character holds), or — under `failed` —
`refused` (the game did not confirm the cast).

**Client work:** render the breakdown. The reasons are the whole point of the
fix; a count alone puts the player back where they started. The single-skill
form (`{ "skill_id": N }`) is unchanged.

Because this call now talks to the game before deciding, it takes a few
seconds. Show a pending state.

## 2. `cast_on_start` changed meaning (no payload change)

The field on `POST /api/v1/runs` is unchanged in name and type. What the
backend does with it has changed:

- **Was:** cast every selected skill once, at run pickup — which is minutes
  before the first blow, since the character still has to navigate to its
  targets or work out which quest step it is on. That time came out of the
  buff's duration.
- **Now:** keep the selected set active, casting just before combat and
  re-casting whatever lapses mid-run.

**Client work:** update the checkbox label and tooltip. Something like
*"Keep selected skills active — cast just before fighting and re-cast when
they expire"* rather than *"Cast selected skills on start"*.

Related: with `require_circumspect: true`, a run that parks waiting for
Circumspect now re-casts **the whole selected set** on resume, not just
Circumspect. That was a reported bug; no client change needed, but the
tooltip for `require_circumspect` can say so.

## 3. "Cast on-start set now" button

The button is still an explicit *cast now* action and still hits the endpoint
in §1. Consider relabelling it (**"Cast selected set now"**) so it is not read
as configuration — the player's feedback was that "Set Now" looked like it
should schedule the casting for the run, rather than doing it immediately.

## 4. New — `GET /api/v1/runs/{run}/events`

The durable run log. Until now every engine message overwrote the same
`last_activity` field, so a finished run could not say what it had skipped.
`last_activity` is unchanged and still the right thing for a live one-liner;
this is the history behind it.

Paginated, newest first.

| Query param | Notes |
| --- | --- |
| `per_page` | 1–100, default 50 |
| `page` | standard |
| `participant_id` | one participant of this run |
| `character_id` | one character |
| `type` | see below |
| `level` | `info` \| `warning` \| `error` |
| `after_id` | only events newer than this id — for live tailing |

Row shape:

```json
{
  "id": 1234,
  "run_id": 7,
  "run_participant_id": 19,
  "character_id": 3,
  "character": "RealLinuXX",
  "type": "quest_skipped",
  "level": "warning",
  "message": "Skipped Cleansing the Church: The giver does not offer this quest.",
  "context": { "quest_id": 743, "position": 12, "reason": "not_available" },
  "created_at": "2026-08-30T11:20:04+00:00"
}
```

`type` values: `skill_cast`, `skill_cast_failed`, `skill_skipped`,
`skill_sync_failed`, `quest_started`, `quest_completed`, `quest_skipped`,
`quest_retry_scheduled`, `objective_progress`, `parked`, `stopped`, `failed`,
`info`.

**Client work:** an "Activity log" tab on the run detail page. Poll with
`after_id` while the run is live. Two `context` shapes are worth rendering
specially:

- `skill_cast` carries `{ cast: [...], skipped: [...], failed: [...] }` — the
  same per-skill breakdown as §1, for one pass.
- `quest_skipped` carries `reason`, one of `no_giver`, `not_available`,
  `unreachable`, `transient_exhausted`, `recorded`, `stuck`,
  `requires_purchased_item`, `outmatched`.

Events older than 30 days are pruned server-side.

## 5. New — per-character quest memory

A quest-list run used to start at quest #1 every time and physically walk the
character to each already-finished giver just to be told it was done. The
backend now records, per character, what the game has settled.

### `GET /api/v1/characters/{character}/quest-progress`

Paginated (`per_page` 1–100), optional `status` filter.

```json
{
  "id": 5,
  "character_id": 3,
  "quest_id": 88,
  "quest": { "game_quest_id": 742, "name": "Street Crawler", "giver": "Stella" },
  "status": "completed",
  "run_id": 7,
  "recorded_at": "2026-08-30T10:02:11+00:00",
  "context": { "level": 61 }
}
```

`status` is `completed` (the engine ran it to the end) or `unavailable` (the
giver did not offer it).

### `DELETE /api/v1/characters/{character}/quest-progress`

Clears one character's memory. Optional `status` query param clears only that
kind. Returns `{ "message": "...", "deleted": 12 }`.

### `DELETE /api/v1/rgas/{rga}/quest-progress`

Same, for every character on the account. Same optional `status`.

**Client work:** a "Clear quest progress" action on the character screen and
on the RGA screen. Both are worth a confirmation.

Two things the UI should tell the player:

1. Quest-list runs now skip recorded quests **before walking anywhere**, which
   is why a restart is much faster than it used to be. Quests marked
   `repeatable` in the catalog are never skipped.
2. `unavailable` is an inference, not a fact. The game is equally silent about
   a quest that is finished and one whose prerequisites are not met yet, so a
   character who has since levelled up may need this cleared before those
   quests are attempted again. Offering `?status=unavailable` as a *"re-check
   quests the giver refused"* action is the friendly version of this — it
   forgets the guesses and keeps the confirmed completions.

## 6. Login now syncs characters in the background

`POST /api/v1/login` queues a character-list read for every RGA on the account
that already has a game session. It **never** logs an account in to do this —
a game login can boot the session the player has open in their own browser.

`RgaResource` gains **`characters_synced_at`** (nullable timestamp).

**Client work:** after login, re-fetch `/rgas` and `/characters` after a short
delay, or poll `characters_synced_at`, so newly discovered characters appear
without a manual sync. The existing "Sync characters" button is unchanged and
still forces an immediate read.

Repeat logins inside 6 hours reuse the last sync rather than sweeping both
game servers again.

## 7. Cosmetic

Participant `last_activity` strings gain new phrasings — buff pass summaries
("Buffs: cast 7, 2 skipped."), objective lines for multi-objective steps, and
retry notices. Nothing parses these; they are display-only.
