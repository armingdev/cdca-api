# Frontend brief — run autonomy changes (API, 2026-08-27)

Audience: a Claude session working in `/Users/armingerina/Code/cdca/cdca-client`
(Angular 22, standalone components, signals + `@angular/forms/signals`, Vitest).

**Nothing in the client is broken by these changes** — every API change is
either additive or a semantic change the client already renders generically.
The work below is about making the new behaviour *configurable* and *legible*,
plus one wording change that is now actively misleading.

---

## 1. What changed on the API

### 1.1 Quest and quest-list runs wait for mob respawns

Previously, a kill or collect objective needing more kills/drops than there
were live mobs ended the run: `stopped`, `last_activity` = "Could not make
progress on objective '…'". Now the participant parks as `waiting` with
`resume_at` and resumes itself until the objective is satisfied, then carries
on to the next step / next quest in the list.

- **New run config field** (modes `quest` and `quest-list`):
  `respawn_wait_seconds`, integer **60–86400**, optional, **default 60**.
  Always echoed back in `run.config`.
- **New participant progress field**: `progress.respawn_waits` — consecutive
  parks that produced nothing. Resets to 0/1 as soon as a cycle makes
  progress. After **30** fruitless waits the run stops for real.
- `stopped` on an objective now genuinely means "unfulfillable" (no known
  source mob, unmapped giver, dead link) — worth surfacing as needing
  attention, unlike before.

### 1.2 Mob runs farm indefinitely by default

**This is the one semantic change that affects an existing control.**
`run_count` of 0 — the default, whether sent as `0` or omitted — now means
*farm indefinitely*: clear the targets, park `waiting`, wait out the respawn,
come back and keep killing. Rage running out no longer ends the farm either;
it parks for the rage window (30 min, or the Circumspect recharge on a gated
run) and continues.

| `run_count` | behaviour |
|---|---|
| `0` or absent | farm indefinitely (**new default**) |
| `N > 0` | N passes, then `completed` (unchanged) |

Before, `0` produced a single pass unless `attack_interval_seconds` or
`require_circumspect` gave the run a reason to cycle. **A one-shot farm now
requires `run_count: 1`.**

An endless farm ends only on `max_kills`, being outmatched, or a manual stop.
There is no give-up counter (unlike the quest respawn wait) — by design.

### 1.3 Circumspect-gated runs park the moment the buff lapses

With `require_circumspect` on, a run used to re-check the buff only at the
start of each pass, so a long pass kept fighting at full rage cost after
Circumspect expired. Now **all modes** (mob, quest, quest-list, PvP) end the
pass the instant the buff window closes, park as `waiting` until the skill is
off cooldown (~12 h for Circumspect), and on resume re-cast the run's selected
skills (`cast_on_start`) plus Circumspect before continuing from persisted
progress.

No response-shape change — but `resume_at` on a gated run can now be many
hours out, where before this path was rarer.

### 1.4 Unchanged

Statuses, endpoints, envelopes, pause/resume/stop semantics. Stopping a run
that is parked `waiting` still finalises it immediately and clears `resume_at`
(now covered by an API test).

---

## 2. Exact strings the client will see

`last_activity` is server-composed and truncated to 250 chars. Current values:

| Situation | `status` | `last_activity` |
|---|---|---|
| Quest objective depleted | `waiting` | `All 'Street Crawler' targets are dead — waiting for respawn. Resumes 2026-08-27 14:32.` |
| Quest-list objective depleted | `waiting` | `Waiting on Quest 742: All 'Street Crawler' targets are dead — waiting for respawn. Resumes 2026-08-27 14:32.` |
| Quest gave up after 30 waits | `stopped` | `All 'Street Crawler' targets are dead — waiting for respawn. Nothing respawned after 30 waits — giving up.` |
| Circumspect lapsed (any mode) | `waiting` | `Circumspect expired. Waiting for Circumspect — resumes 2026-08-27 21:14.` |
| Mob farm cleared the room | `waiting` | `Pass 3 cleared the targets — waiting for respawns, back at 2026-08-27 14:32.` |
| Mob farm rage-out | `waiting` | `Rage depleted — pass 3 done, next at 2026-08-27 14:32.` |
| Mob farm interval pass | `waiting` | `Pass 3 complete — next at 2026-08-27 14:32.` |
| Bounded mob farm finished | `completed` | `Reached 2 pass(es).` |

Do **not** parse these for logic. They are shown to humans and will be
reworded. Where behaviour must branch, branch on `status`, `mode`, `config`,
and `progress`.

---

## 3. Client changes

### 3.1 Models — `src/app/core/models/run.ts`

```ts
// RunConfig (~:130-176)
/** Wait before re-checking rooms whose targets were all dead, 60–86400 s
 *  (quest + quest-list). Absent on runs created before 2026-08-27. */
respawn_wait_seconds?: number;

// RunProgress (~:179-190)
/** Consecutive respawn waits that produced nothing (quest modes); the run
 *  gives up after 30. */
respawn_waits?: number;

// StartQuestRun and StartQuestListRun
respawn_wait_seconds?: number;
```

Also update the `run_count` doc comment in `RunConfig` — it currently reads
"0 = unbounded", which was aspirational and is now literally true; say
"0/absent = farm indefinitely".

### 3.2 New-run form — `src/app/features/runs/new-run-page.{ts,html}`

**Mob mode wording (required — currently misleading).**
`new-run-page.html:149-160` labels the control "Number of runs (0 =
unbounded)" with the hint "Full passes per character — one pass clears the
selected mobs or hits the rage floor." Under the old API, 0 quietly meant one
pass. Now it really does farm forever. Reword to make the consequence
obvious, e.g. label "Number of runs (0 = farm indefinitely)" and a hint that
says a run of 0 keeps going through respawns until you stop it, and that
**1 means a single pass**. Update the matching validation message at
`new-run-page.ts:236`.

Check what the form seeds `run_count` to. Leaving the default at 0 matches the
product intent (autonomy), but the user must be able to tell that is what they
are getting — consider a "Run once" affordance that sets 1.

**Quest and quest-list: new optional control.** Add `respawn_wait_minutes:
number | null` to `RunFormModel`, following the existing
`attack_interval_minutes` pattern exactly (minutes in the UI, seconds on the
wire):

- validate 1–1440 minutes (the API accepts 60–86400 seconds);
- in `buildRequest()` (`new-run-page.ts:445-516`) quest and quest-list
  branches, spread `{ respawn_wait_seconds: m.respawn_wait_minutes * 60 }`
  only when non-null and > 0, so an untouched form inherits the server's
  60-second default;
- add `respawn_wait_seconds: this.runForm.respawn_wait_minutes` to the 422
  field map at `new-run-page.ts:388-404` (same divergence handling as
  `attack_interval_seconds`).

Suggested copy: "Respawn wait (minutes, empty = 1 min)" with a hint that the
character parks and re-checks the rooms when every target is dead.

### 3.3 Run detail — `src/app/features/runs/run-detail-page.{ts,html}`

**`configEntries()` (`run-detail-page.ts:268-337`).** The "Passes" row already
maps `run_count === 0` to `'unbounded'` — reword to `'indefinite'` for
consistency with the form. Add a "Respawn wait" row for quest modes using the
existing `formatDuration()`.

**Parked participants currently hide the reason — this is the highest-value
change.** `run-detail-page.html:189-212` replaces `last_activity` with
"resumes at HH:MM" whenever `p.status === 'waiting'`. Under the old API a park
was almost always the Circumspect cycle, so the reason was implicit. Now a
parked participant can be waiting for a respawn (~1 min), for rage (~30 min),
or for Circumspect (~12 h) — and the user can no longer tell which. Show a
short reason alongside the resume time. Deriving it from `last_activity` is
fragile; prefer the signals you already have: `run.require_circumspect`, the
distance to `resume_at`, and `progress.respawn_waits`. If you do fall back to
text, keep it to a documented fragment matcher like the existing `'Outmatched'`
/ `'on cooldown'` ones in `run.ts`, and note that these strings are unstable.

**Endless runs never reach a terminal status.** `canDeleteRun` therefore never
becomes true for a default mob farm until it is stopped. Make sure Stop stays
prominent, and consider labelling such a run ("farming indefinitely") so the
absence of a finish line reads as intentional rather than stuck.

### 3.4 Status helpers — `src/app/shared/status.ts`

- `progressSummary()` has no quest branch (always `—`). Quest runs now carry
  meaningful state; at minimum surface `progress.respawn_waits` when > 0
  ("waiting on respawns ×3") so a stuck-looking run explains itself.
- Verify the mob branch reads well when `run_count` is 0 — the denominator is
  only used when `> 0`, so it should render "pass 3" rather than "pass 3/0".
- `statusChipClass()` needs no change (no new statuses).

### 3.5 Polling — `src/app/features/runs/run-detail-page.ts:30-39`

`runPollDelayMs()` clamps a waiting run to `min(60s, max(5s, time-to-resume))`.
That is right for a 60-second respawn wait but means ~700 polls across a 12-hour
Circumspect park. Consider letting the cap scale with the distance to
`resume_at` (e.g. poll every few minutes when resume is more than an hour out)
while keeping the tight cadence near the wake time. The `until` predicate needs
no change.

`runs-page.ts` already backs off to 30 s when every run is terminal or parked —
fine as is.

---

## 4. Tests

Vitest, colocated `*.spec.ts`, run with `pnpm test`.

- **Fixtures first**: `src/app/core/api/testing/fixtures.ts` is typed as `Run`,
  so add the new optional fields there before touching specs. A
  `waitingRunFixture` already exists (`:310`); add quest-mode variants for a
  respawn park and a Circumspect park.
- `new-run-page.spec.ts` — existing assertions at `:135-136` (`run_count: 0`
  sent, no interval) and `:194-217` (minutes→seconds) stay valid. Add: quest
  run sends `respawn_wait_seconds` when the field is filled and omits it when
  empty.
- `run-detail-page.spec.ts` — assert the parked reason renders for a respawn
  park vs a Circumspect park.
- `status.spec.ts` — quest `progressSummary` branch; mob summary with
  `run_count: 0`.
- `runs-page.spec.ts` / `poller.spec.ts` — only if you change the backoff.

---

## 5. Deliberately not changing

Verified in the client during this review, so don't go hunting:

- No "single pass" assumption exists — the client is already cycle-aware
  (`cycles_done`, the "Passes" config row, the mob form hint).
- No mode-conditional terminal logic; terminality comes solely from
  `isTerminalRunStatus()` on the server status.
- No string-matching on "Could not make progress" or rage-out text anywhere.
- `waiting` is already a first-class status with chip, banner, resume hint and
  adaptive polling.

---

## 6. Reference

Canonical API docs, kept in sync in this repo:

- `/Users/armingerina/Code/cdca/cdca-api/docs/api-changes-run-system.md` —
  §4b (quest respawn waits), §4c (endless mob farms), §4d (Circumspect parks).
- `/Users/armingerina/Code/cdca/cdca-api/docs/api-reference.md` — mode-specific
  field table.

The client mirrors these at `cdca-client/docs/api-reference.md`; update that
mirror as part of the work.
