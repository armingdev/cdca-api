# API Changes — Fleet Skill Selection & Automatic Skill Sync (2026-09-06)

Audience: the Angular client repo (`cdca-client`). Backend side is **merged and
tested** (661 tests green, PHPStan level 6 clean). This covers four requested
improvements:

1. Pre-select a character's saved skills when it is selected again.
2. Select all / deselect all per skill school.
3. Fetch skill state automatically when a character is selected.
4. **(Highest priority)** configure the cast-on-start skills for many
   characters in one place and start the run for all of them.

Everything is **additive** — no existing field changed meaning or shape.
There is one **pre-existing client bug** to fix, described in §0.

## TL;DR

| Feature | Backend change | What the client does |
| --- | --- | --- |
| 1. Persist selection per character | none needed — already persisted | Pre-select from `cast_on_start`, on the run page too |
| 2. Select / deselect all per school | none needed | UI over the catalog grouped by `skill.school` |
| 3. Auto sync on select | `POST /characters/{id}/skills/sync` takes `max_age_seconds` + `with_recharge`; rows gained `buff_ends_at`, `cooldown_ends_at`, `ready` | Sync automatically on select with a freshness cap |
| 4. Multi-character run config | `POST /runs` takes `skill_ids`; `RunResource` echoes it | One picker for the fleet, one request to start |

---

## 0. Fix first — the sync response is not `data`-wrapped

`SkillsApi.sync()` currently types its list as `Envelope<CharacterSkill[]>` and
the Skills page reads `response.skills.data`. The API has always returned
`skills` as a **plain array**, so that read is `undefined` and the page blanks
its own skill list every time the user clicks "Sync from game". The unit test
does not catch it because `skillSyncResponseFixture` in
`core/api/testing/fixtures.ts` invents the envelope.

The backend keeps the plain array: it matches the sibling
`POST /characters/{id}/teleports/sync`, whose client type already documents
"a plain array, no envelope".

```ts
// core/api/skills-api.ts
export interface SkillSyncResponse {
  // …
  /** The full refreshed list — a plain array, no envelope. */
  skills: CharacterSkill[];
}
```

Then `response.skills` at both call sites, and drop the `{ data: … }` wrapper
from the fixture. Feature 3 makes this urgent: once sync runs automatically on
every character selection, the bug would blank the list on every selection.

---

## 1. `POST /api/v1/runs` — new optional `skill_ids`

```json
{
  "mode": "mob",
  "characters": [12, 13, 14],
  "skill_ids": [3008, 9, 4],
  "require_circumspect": true,
  "mobs": ["Fire Elemental"]
}
```

| Field | Type | Rules |
| --- | --- | --- |
| `skill_ids` | `number[]` | optional; every id must exist in the catalog; duplicates are removed |

Behaviour:

- **Omitted** → unchanged: each character keeps the selection it already has.
  The run stores `skill_ids: null`.
- **Non-empty** → **replaces** the cast-on-start set of **every** character in
  `characters`, then starts. `cast_on_start` is turned on automatically, so
  you do not have to send it. Sending an explicit `cast_on_start: false`
  alongside a non-empty list is a `422` on `cast_on_start`
  ("Turn on cast-on-start or leave the skill selection empty.").
- **`[]`** → clears every listed character's selection. Does **not** turn
  `cast_on_start` on (there is nothing to cast).
- The write lands in the same per-character table the Skills page uses. That is
  deliberate: it is what makes feature 1 work across both pages — next time one
  of those characters is picked alone, its pre-selection is the set the last run
  used.
- One request covers the whole fleet. **Never** loop
  `PUT /characters/{id}/skills` before starting a run.
- Untrained skills in the set are harmless: the engine skips them per character
  with reason `untrained` in the run's `skill_cast` events. So a school a given
  character never committed to just produces skipped rows, not failures. This is
  what makes a single fleet-wide selection safe across mixed characters.

`RunResource` gains `skill_ids: number[] | null` — null on every run started
without one, including all existing runs. Use it to pre-fill "same as last
time".

---

## 2. `POST /api/v1/characters/{id}/skills/sync` — freshness guard + cooldowns

A body is now accepted; the bare call behaves exactly as before.

| Field | Type | Default | Meaning |
| --- | --- | --- | --- |
| `max_age_seconds` | int 0–86400 | absent = always sync | Skip the game read when the stored state was synced more recently than this. |
| `with_recharge` | bool | `false` | Also read every **trained** skill's authoritative recharge window. One extra throttled game request per trained skill. |

Response — same shape on both paths:

```json
{
  "message": "Synced 43 skill(s).",
  "synced": true,
  "synced_at": "2026-09-06T10:12:03.000000Z",
  "rows_synced": 43,
  "skills_discovered": 0,
  "skill_points": 15,
  "school": "ferocity",
  "active_buffs": 3,
  "skills": [ /* CharacterSkill[] — plain array, see §0 */ ]
}
```

- `message`, `synced`, `rows_synced` and `skills_discovered` describe **what
  this call did**. Everything else describes **the character as it now
  stands**, so the client can render the response without branching on
  `synced`. In particular `active_buffs`, `skill_points` and `school` are real
  values on the guard path too, not zeros.
- `synced: false` → the guard fired: `message` is "Skills are up to date.",
  both counts are `0`, `synced_at` is the stored stamp it compared against, and
  no game request was made.
- `synced_at` is null only when the character has never been synced (which also
  means the guard can never fire).
- `422 { message }` when the RGA has no session or a page could not be parsed —
  unchanged.

### Cost model

| Call | HTTP | Game requests | Wall time |
| --- | --- | --- | --- |
| `GET /characters/{id}/skills` | 1 | 0 | instant |
| `sync`, guard fires | 1 | 0 | instant |
| `sync`, stale | 1 | 5 | ~2–4 s |
| `sync`, stale, `with_recharge` | 1 | 5 + one per trained skill | ~5–15 s |

---

## 3. `CharacterSkill` rows — three fields added

Present on `GET /characters/{id}/skills`, the `skills` list of a sync, and the
`skill` of a train response.

| Field | Type | Meaning |
| --- | --- | --- |
| `buff_ends_at` | ISO string \| null | When the active buff lapses. Null = not active. |
| `cooldown_ends_at` | ISO string \| null | When the cooldown ends. Null = ready. |
| `ready` | boolean | `castable && !on_cooldown`. Rage is not considered. |

Use these for countdowns instead of computing `last_cast_at + duration`
yourself. The server resolves a subtle precedence the raw columns do not
express: a fresh server reading beats the local estimate, which matters because
several skills last longer than they recharge (Empower buffs for 180 minutes and
recharges in 120). `buff_active` / `on_cooldown` keep their meaning and stay the
booleans to render.

For "can I cast this right now, rage included", compare
`current_rage_cost ?? skill.rage_cost` against the character's `rage`.

Rows exist for every catalog skill only **after** a sync. Before that the list
holds only skills ever selected or cast — treat a missing row as *unknown*, not
*untrained*.

### Type changes

```ts
export interface CharacterSkill {
  // …existing fields unchanged…
  /** When the active buff lapses. null = not active. */
  buff_ends_at: string | null;
  /** When the cooldown ends. null = ready. */
  cooldown_ends_at: string | null;
  /** castable && !on_cooldown — rage not considered. */
  ready: boolean;
}

export interface SkillSyncRequest {
  /** Skip the game read when the stored state is newer than this (0–86400). */
  max_age_seconds?: number;
  /** Also read every trained skill's recharge window (+1 game request each). */
  with_recharge?: boolean;
}

export interface SkillSyncResponse {
  message: string;
  /** false = the freshness guard fired and nothing was read from the game. */
  synced: boolean;
  synced_at: string | null;
  rows_synced: number;
  skills_discovered: number;
  skill_points: number | null;
  school: TrainableSchool | null;
  active_buffs: number;
  /** Plain array — see §0. */
  skills: CharacterSkill[];
}

export interface StartRunRequest {
  // …existing fields…
  /** Replaces every selected character's set; omit to keep each character's own. */
  skill_ids?: number[];
}

export interface Run {
  // …existing fields…
  /** What the run applied to its fleet; null = each character's own set. */
  skill_ids: number[] | null;
}
```

`SkillsApi.sync(characterId, body?: SkillSyncRequest)` — add the optional body,
keep the no-arg call working.

---

## 4. Frontend implementation

### 4.1 Shared skill picker (new component)

One component, e.g. `features/skills/skill-picker`, used by the Skills page and
the New Run page.

- Inputs: `catalog: Skill[]`; `states: Map<number, CharacterSkill> | null`
  (only when exactly one character is in scope); `disabled`.
- `selectedIds = model<number[]>([])` for two-way binding.
- Group by `skill.school` in the order `class, ferocity, preservation,
  affliction, misc`. Each group is a `<fieldset>` with a `<legend>` carrying the
  school name, an "n / m selected" count, and **Select all** / **Deselect all**
  buttons (feature 2).
- Select-all scope: with per-character states available, tick only rows where
  `castable` is true — selecting known-untrained skills only adds `untrained`
  skip rows to the run log. Without states (multi-character, or never synced),
  tick every skill in the group. Deselect-all always clears the whole group.
- Single-character mode shows the state chips the Skills page already renders,
  plus a countdown from `cooldown_ends_at` and a "ready" marker. Multi-character
  mode shows only name, school and rage cost — **no per-character detail is
  fetched**, which is the explicit product decision for feature 4.
- Cache the catalog in a service and fetch it once per app session: 43 rows that
  never change at runtime.
- Accessibility: native checkboxes, `aria-controls` from each button to its
  fieldset id, and the count in the legend so it is announced with the group.

### 4.2 Skills page (single character)

- Keep per-toggle `PUT /characters/{id}/skills` persistence and the
  `cast_on_start` pre-selection. Feature 1 already works here.
- In `onCharacterChange`, fire both of these and render whichever lands first:
  1. `GET /characters/{id}/skills` — instant, database only;
  2. `POST …/skills/sync` with `{ max_age_seconds: 300, with_recharge: true }` —
     replace rows, `skill_points` and `school` from the response when it
     resolves.
  Show an unobtrusive "refreshing from game…" indicator; never block the
  checkboxes on it.
- Discard a sync response whose character id no longer matches the current
  selection. Without that guard, switching characters mid-flight paints one
  character's skills under another's name.
- If the sync fails (`422`, no session), show the message inline and keep the
  `GET` rows. The page stays usable.
- Keep the manual "Sync from game" button but demote it. It calls sync with
  `{ with_recharge: true }` and **no** `max_age_seconds`, i.e. force.
- Select-all issues **one** `PUT` with the merged list, never one per skill.

### 4.3 New Run page — feature 4, the priority

- Add a "Skills to keep active" section under the existing "Keep selected skills
  active" checkbox, using the shared picker in multi-character mode.
- Pre-selection when the fleet selection changes:
  - **exactly one** character → `GET /characters/{id}/skills`, pre-select rows
    with `cast_on_start: true`, and fire the same background sync as the Skills
    page. Cache per character id for the page's lifetime.
  - **two or more** → fetch **nothing** per character. Pre-select from the most
    recent run's `skill_ids` (`GET /runs?per_page=1`, when not null), else keep
    what the user already ticked, else empty. Show a one-line hint: "Applied to
    all N selected characters and saved as their cast-on-start set."
- Send `skill_ids` **only when the user touched the picker** (track a `dirty`
  flag). Untouched means omit, so every character keeps its own set — which is
  today's behaviour and what someone who configured per-character sets on the
  Skills page expects.
- Do not send `cast_on_start: false` while skills are ticked; that is a `422`.
  Either clear the picker or disable it when the checkbox is off.
- Never call `PUT /characters/{id}/skills` from this page.
- Show `422` errors on `skill_ids` next to the picker via the existing
  `validationErrors(error)` helper.

### 4.4 Run detail page

Render `skill_ids` as skill names resolved through the cached catalog, beside
the existing cast-on-start and Circumspect badges. Null → "each character's own
set".

### 4.5 Request budget

| Action | Requests |
| --- | --- |
| Open Skills page | 1 for characters; catalog cached |
| Select a character | 2 — instant `GET`, plus a sync that costs the game nothing when fresh |
| Select all in a school | 1 `PUT` |
| New Run: 10 characters + skills + start | 1 `POST /runs`, plus one `GET /runs?per_page=1` per page load |
| New Run: exactly 1 character | 2, cached per character for the page's lifetime |

---

## 5. Backend changes shipped (this repo)

1. Migration `add_skill_ids_to_runs_table`: nullable json `runs.skill_ids`; cast, fillable, `RunResource`, `RunFactory`.
2. `StoreRunRequest`: `skill_ids` with an array-level `Rule::exists` (one query for the whole list) and an `after()` hook rejecting the contradictory `cast_on_start: false`.
3. New `App\Game\Skills\SkillSelection` — `replaceFor()` / `set()`, the upsert-and-clear pair that used to be inline in the controller. Now shared by `CharacterSkillController`, `SkillsCommand`, and `RunLauncher`.
4. `RunLauncher::launch(skillIds:)` applies one selection across the fleet before dispatching any worker and records it on the run.
5. New `SyncCharacterSkillsRequest`; `SkillSyncService::sync(bool $withRecharge)` + `lastSyncedAt()`; a guarded recharge pass that degrades to the stored estimate rather than failing the sync.
6. `CharacterSkillResource`: `buff_ends_at`, `cooldown_ends_at`, `ready`.
7. Tests: 9 new cases in `RunApiTest` and `CharacterApiTest` covering fleet apply, omit, clear, both rejections, the guard, a stale sync, the recharge pass, and the new row fields. `docs/api-reference.md` updated.
