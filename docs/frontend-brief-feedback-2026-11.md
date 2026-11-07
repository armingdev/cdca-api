# Frontend brief — tester feedback batch (API, 2026-11)

Audience: a Claude session working in `/Users/armingerina/Code/cdca/cdca-client`
(Angular 22, standalone components, signals + `@angular/forms/signals`, Vitest).

A tester sent a new list of wishes. The API is built phase by phase and this
brief grows with it. **All four phases are done**: accounts and sessions,
multiple quest lists and run priority, potions, and spawned mobs. Every API change so far is additive:
nothing in the client breaks if none of this is done yet.

Full endpoint shapes: `cdca-api/docs/api-reference.md` (§2 auth, §4 RGAs →
Sessions / Challenge tokens, §5 characters, §5b crew groups).

Work order suggestion: §1.6 (favicon, five minutes) and §1.5 (fleet level
range) first, then the password screens, then sessions, tokens and crew groups.

---

## 1. Accounts and sessions (Phase 1 — API done)

### 1.1 Forgot password / change password

New endpoints (all JSON):

- `POST /forgot-password { email }` → always `200` with a generic message
  (the API never says whether the email exists — show the message as-is).
- `POST /reset-password { token, email, password, password_confirmation }` →
  `200`; a bad/expired link → `422 errors.email` "This password reset link is
  invalid or has expired.". **All tokens are revoked**: send the user to
  `/login` with a "password changed, sign in" notice.
- `PUT /user/password { current_password, password, password_confirmation }`
  (authed) → `200`; this device stays signed in, every other device is signed
  out. `422` on `current_password` (wrong) or `password` (too short, not
  confirmed, or the same as the current one).

The reset email (Postmark, from `no-reply@cdcaow.com`) links to
**`{client}/reset-password?token=…&email=…`**, so the client needs that route.

Client:

- `features/auth/login-page.html` — add a "Forgot password?" link under the
  password field → new `forgot-password` route (guest-only, `guestGuard` like
  `login`/`register` in `app.routes.ts:17-27`). One email field; on submit
  show the API message and keep the form disabled for ~60 s (the API throttles
  a repeat to one per minute per user anyway).
- New `reset-password` route (guest-only): read `token` and `email` from the
  query string, show new password + confirm, `POST /reset-password`, then
  navigate to `/login` with a success banner. Show `errors.email[0]` on 422
  with a link back to "Forgot password?".
- Signed-in "Change password" form — there is no settings page today; the
  natural home is a small card on `features/accounts/accounts-page` or the
  user menu in `layout/shell.html`. Fields: current, new, confirm. Map the
  422 field errors to the inputs.
- `core/api` has no auth API file for this — add the three calls next to the
  existing login/register calls used by `core/auth/auth-store.ts`.

### 1.2 Copy all active sessions with one click

`GET /rgas/sessions[?verify=1]` → `{ data: [{ rga_id, username, rg_sess_id,
token, cuserid2, status, last_login_at, characters_count, alive }] }`, ordered
by username, only RGAs with a live session.

- Without `verify` it answers from the database instantly (`alive: null`).
- With `verify=1` the API asks the game about each session (about 1 s per
  RGA): `alive: false` means the game rejected it and the RGA is now
  `invalid`; `alive: null` means the game could not be reached.

Client (`features/accounts/accounts-page`):

- A "Copy all sessions" button in the page header. Call with `verify=1`,
  then copy one line per live session to the clipboard. Suggested format
  `username: rg_sess_id` (the tester pastes into other tools — confirm the
  format with the tester; plain ids one per line is the alternative).
- Show a toast: "Copied N sessions" plus "M sessions are dead — log in
  again?" listing the `alive: false` usernames. Re-logging is the existing
  `POST /rgas/{id}/login` (`rgas-api.ts:43`).
- `GET /rgas/{id}/session` (the existing per-RGA reveal) now also returns
  `rga_id` and `username`.

### 1.3 Crew leader mode (crew groups)

A cdca crew: a leader and members who joined with an invite code. Each
member picks which of **their own** RGAs the leader may read; the leader
copies those sessions in one click. Every leader read is logged and visible
to all members. Full table in api-reference §5b.

Client — new feature folder, e.g. `features/crew-groups`, plus a nav entry in
`layout/shell.html`:

- **List** (`GET /crew-groups`): name, leader, members count, a "Leader"
  badge on `is_leader`. Buttons: "Create crew" (`POST { name }`) and "Join
  with code" (`POST /crew-groups/join { invite_code }`; show
  `errors.invite_code[0]` on 422).
- **Detail** (`GET /crew-groups/{id}`):
  - Leader view: invite code with copy and "New code" buttons (`POST …/invite-code`),
    rename, disband (confirm dialog), the members list with each member's
    `shared_rgas_count` and a remove button (`DELETE …/members/{userId}`),
    and **"Copy crew sessions"** → `GET …/sessions?verify=1`, copying the
    rows with `has_session: true` in the same format as §1.2. The rows also
    list each RGA's characters (name, level, server) — show them in a table
    under the button.
  - Member view: "My shared accounts" — a checklist of the user's own RGAs
    (from `GET /rgas`) pre-ticked from `shared_rgas`; saving sends the full
    set to `PUT …/shared-rgas { rga_ids }` (`[]` = share nothing). Plus a
    "Leave crew" button (`DELETE …/members/{myUserId}`).
  - Both: "Session reads" — the audit trail (`GET …/session-reads`,
    paginated): who read, when, how many accounts, verified or not.
- Explain the trust model in the UI copy: sharing gives the leader full
  control of that game account until the member un-shares it or logs the
  account in again elsewhere.

### 1.4 Auto-claim challenge tokens

Challenge tokens: 3 per day per RGA, at most 30 held. The API now claims
them automatically (scheduler, every 30 min) for every RGA with
`auto_claim_tokens` on — **on by default**.

- `RgaResource` gains `auto_claim_tokens`, `challenge_tokens`,
  `challenge_tokens_purchased`, `challenge_claim_at` (null until first read).
- `PUT /rgas/{id} { auto_claim_tokens: bool }` toggles it.
- `GET /rgas/{id}/challenge-tokens` reads live; `POST …/challenge-tokens/claim`
  claims now (`200` + `message`; `422` + the game's reason, e.g. "You have
  reached your max of 30 challenge tokens!").

Client (`features/accounts/accounts-page`): per RGA row, show
"Tokens 27/30 (+5 bought)" and the next claim time, an "Auto-claim" toggle,
and a "Claim now" action that shows the returned `message`.

### 1.5 Fleet: filter by level range

`GET /characters` accepts `min_level`/`max_level`, but the fleet grid filters
client-side, so this is a client change.

Client (`features/fleet`):

- Copy the quest finder's two level inputs and their hint
  (`features/quests/quest-finder-page.html:37-65`; the `minLevel`/`maxLevel`
  signals, `levelRangeInvalid`, `onMinLevelChange`/`onMaxLevelChange` in
  `quest-finder-page.ts:39-40,55,120-125`) into `fleet-page.html` next to the
  search field.
- Add `(min === null || c.level >= min) && (max === null || c.level <= max)`
  to the `filtered` predicate in `fleet-page.ts:58-88`, ignoring the range
  while it is invalid.
- The existing "select all filtered" checkbox (`toggleAllFiltered()`,
  `fleet-page.ts:132`) then selects exactly the characters in the range, and
  they carry into "New run" through `FleetSelection`.
- Spec: a 40–60 range shows only levels 40–60, and select-all selects
  exactly them.

**API bug fixed along the way:** `GET /quests?max_level=30` without a
`min_level` used to be a `422` ("must be greater than or equal to min
level"). It now works. If the quest finder had a workaround for that, it can go.

### 1.6 Favicon

`public/favicon.ico` is still the Angular CLI default (15,086 bytes, from
2026-07-22). `public/favicon.svg` already draws the header mark (the
`rage-500` clipped square from `shared/brand.ts`). Browsers that pick the
`.ico` (Safari, and Chrome when `sizes` matches) show the Angular logo.

- Regenerate `favicon.ico` (16/32/48) from `favicon.svg`.
- Add `<link rel="apple-touch-icon" href="apple-touch-icon.png">` (180×180,
  the mark on `#131110`) to `src/index.html`.
- Keep the SVG `<link>` first in `index.html:10-11`.

---

## 2. Run orchestration (Phase 2 — API done)

### 2.1 Run several quest lists together

A quest-list run now takes **`quest_list_ids: number[]`** (1–10 distinct
lists of the user's own), played back to back in the given order. A
non-repeatable quest finished in an earlier list is skipped in a later one.
`quest_list_id` (single) is still accepted, and `config.quest_list_id` mirrors
the first list. Unknown/foreign ids → `422 errors.quest_list_ids` naming them.

Progress gains `list_index` (0-based index into `config.quest_list_ids`) next to
`position`. The run log (`GET /runs/{id}/events`) gets an `info` event
"Running quest list 'X' — list 2 of 3 (…)" at each list start, and
`quest_skipped`/`quest_completed`/`quest_retry_scheduled` contexts now carry
`quest_list_id` (`position` alone is ambiguous across lists).

Client:

- `features/runs/new-run-page.html:373-395` — replace the single `<select>`
  with an ordered multi-pick (add list, remove, move up/down; max 10). The
  crew picker (`features/runs/crew-picker.ts`, `MAX_CREWS`) is the precedent
  for an ordered id list with a cap. Form model: `quest_list_ids: number[]`
  instead of `quest_list_id` (`new-run-page.ts:95,275,390,680,843-846`);
  validation "Choose at least one quest list."
- Run detail (`features/runs/run-detail-page`): for quest-list runs, show
  "List 2/3 · <name> · quest 5" from `progress.list_index` / `position`
  (names from `GET /quest-lists`, matched by `config.quest_list_ids`).
- `run-activity-log.html:84-85` shows `position #N` for skips — prefix the
  list name when `context.quest_list_id` is present.

### 2.2 Run priority

Runs have **`priority: 'high' | 'normal' | 'low'`** (default `normal`).
When every run worker is busy, waiting participants of a higher-priority run
get the next free worker first. It never interrupts a running character.

- `POST /runs { …, priority }`.
- `PATCH /runs/{id} { priority }` — any status. Participants still waiting
  for a worker (`pending`) move to the new queue at once; parked (`waiting`)
  ones use it at their next resume. `name` and `priority` can be sent
  together or alone (a PATCH with neither is still a `422` on `name`).
- `RunResource.priority` is always present.

Client:

- New-run form: a three-way segmented control "Priority: High · Normal · Low"
  (default Normal) with the hint "Decides who starts first when all run
  workers are busy."
- Runs list (`features/runs/runs-page.html:36-60`): a small "High"/"Low" chip
  next to the status chip (nothing for normal), and a priority menu on each
  row that PATCHes it. Most useful on `pending` runs — consider surfacing it
  next to the "starts at" line.

---

## 3. Potions (Phase 3 — API done)

Potions are drunk like teleport items are activated. A run can (a) keep
selected **buff** potions active, like cast-on-start skills, and (b) drink
selected **rage** potions instead of parking for the hourly rage tick, up to
a cap per run cycle. Shapes in api-reference §5 "Per-character potions", §6
`GET /potions`, §8 `POST /runs`.

- Catalog: `GET /potions?kind=buff|rage|other&search=` — 139 rows, not
  paginated. `kind` decides where a potion may be picked: `buff` for keep-active,
  `rage` for rage refills, `other` only for manual use. Images:
  `https://sigil.outwar.com/images/{image}`.
- Character: `GET /characters/{id}/potions` (stored), `POST …/potions/sync`
  (live), `POST …/potions/{potionId}/activate` (drink now; `200` drank, `422`
  with `outcome` = `already_active` | `out_of_stock` | `refused` + `message`).
- Run fields: `buff_potion_ids: number[]`, `rage_potion_ids: number[]`
  (order = try order), `max_rage_potions: number` (1–50, required with rage
  potions). Echoed on `RunResource` (`[]`/`0` when unused).
- Run log: new event types `potion_used` (context `potion`, `kind`,
  `quantity_left`, plus `rage_potions_used`/`max_rage_potions` for rage) and
  `potion_skipped` (warning; context `reason` = `out_of_stock` | `refused` |
  `read_failed`). A rage refill also shows in `last_activity`: "… Drank Rage
  Potion for rage (1/3) instead of waiting for the rage tick."

Client:

- New-run form (`features/runs/new-run-page`): a "Potions" section next to
  the skill picker (`new-run-page.html:759` area).
  - "Keep active": a multi-select of `kind=buff` potions.
  - "When out of rage": an ordered multi-select of `kind=rage` potions, plus
    "at most N per run" (shown only when a rage potion is picked).
  - Nice to have: annotate each option with how many of the selected
    characters hold it (`GET /characters/{id}/potions`, `quantity > 0`).
- Character page (`features/characters/character-page`): a "Potions" panel
  like `travel-panel` — the held stacks with quantity, an "active, 42 min
  left" badge from `active_until`, a "Drink" button per row (shows the
  returned `message`; disable while `refused_until` is in the future), and
  "Refresh" → `POST …/potions/sync`. `other`-kind potions can be shown
  collapsed.
- `core/models/run-event.ts` — add `'potion_used' | 'potion_skipped'` to
  `RunEventType` and render them in `run-activity-log` (potion name + quantity
  left; warnings for skipped).

---

## 3b. Spawned mobs (Phase 4 — API done, almost nothing for the client)

Some mobs only exist once spawned: killing their room-mates draws them out
("{Mob} steps out of the shadows!"). Runs now handle it themselves, both in
mob mode (pick the spawnable mob as a farm target) and for quest objectives;
before, a quest needing one just waited in the room. No new request fields.

- `GET /world/mobs` items gain `spawnable: boolean`. In the mob picker of
  the new-run form (`features/runs/new-run-page`, the mob search), show a
  small "spawn" badge with the tooltip "Appears only after its room-mates are
  killed — the run farms them for you."
- While hunting, `last_activity` reads "Possible Pyrite needs to be spawned —
  farming its room-mates.", then "Beat A Rock to draw out Possible Pyrite
  (3/40).", then "Possible Pyrite steps out of the shadows!". Nothing to
  build; it shows in the existing activity line.
- `participant.wins` counts every won fight, room-mates included;
  `progress.kills_done` (mob mode) and quest progress count only the target.
  If the run detail shows "kills / max_kills", use `kills_done`.

## 3c. Quest lists that tell the truth (stability work — API done)

Testers saw lists end "complete" (or "error") with most quests undone. A list
that puts level-90 quests first used to walk a level-83 character to every
level-90 giver, skip each one, and call the list complete. Now:

- A quest above the character's level is **deferred**, not attempted (the run
  levels into it first when `level_up` or `smart` is on). A quest whose
  prerequisite was deferred is deferred too. When a pass over the list ends
  and the character has grown into a deferred quest, the run automatically
  replays the lists running only the deferred quests ("pass 2", "pass 3"…).
- The participant can finish `completed` with quests still deferred.
  `last_activity` says so: "Quest list done for now: 12 completed, 70 deferred
  until level 85–92, 6 skipped." versus "Quest list complete: 40 completed,
  3 skipped." when nothing is left waiting.
- `progress` (quest-list) now carries, all run-wide: `quests_completed`,
  `quests_skipped`, `quests_deferred`, `deferred` (`{questId: reason}`),
  `skipped_by_reason` (`{reason: count}`), `pass`, `retrying`.
- Suggested UI, run detail participant row: "12 done · 70 waiting · 6 skipped"
  from those counters. Tooltip on "waiting": "Above this character's level (or
  behind a quest that is) — retried automatically as it levels." A small
  "pass 2" chip when `pass > 1`.
- New run event type `quest_deferred` (info): one per list, e.g. "Deferred 80
  quest(s) in '80-95 GOOD' until the character is ready: 78 need level 85–92,
  2 wait on a deferred prerequisite." Show it like `quest_skipped` but neutral
  (not a warning colour).
- Gate refusals now read cleanly ("Entry to room 42622 refused: To enter this
  room you must be carrying Triworld Teleporter." — no stray HTML), and a
  quest whose giver is past a gate the character was already turned away
  from is skipped without walking there: "Quest-giver 'Trelon' is past gated
  room 42622 (Triworld Teleporter)."

---

## 4. Type changes checklist (`core/models`)

- `rga.ts`
  - `Rga` += `auto_claim_tokens: boolean`, `challenge_tokens: number | null`,
    `challenge_tokens_purchased: number | null`, `challenge_claim_at: string | null`.
  - `UpdateRgaRequest` += `auto_claim_tokens?: boolean`.
  - `RgaSession` += `rga_id: number`, `username: string`.
  - New `RgaSessionRow extends RgaSession { characters_count: number; alive: boolean | null }`.
  - New `ChallengeTokens { rga_id; auto_claim_tokens; tokens: number | null; max_tokens: 30; purchased_tokens: number | null; next_claim_at; claimed_at; synced_at }`.
- `user.ts` — new request types `ForgotPasswordRequest`, `ResetPasswordRequest`,
  `UpdatePasswordRequest`.
- New `crew-group.ts` — `CrewGroup`, `CrewGroupMember`, `CrewGroupSharedRga`,
  `CrewGroupSession` (with `characters: { suid, server_id, name, level }[]`),
  `CrewGroupSessionRead`.
- `characters-api.ts` — optional `min_level`/`max_level` query params.
- `run.ts`
  - `Run` += `priority: RunPriority` (`type RunPriority = 'high' | 'normal' | 'low'`).
  - quest-list config += `quest_list_ids: number[]` (`quest_list_id` stays, deprecated).
  - participant progress += `list_index?: number`.
  - create/update run requests += `priority?: RunPriority`; create += `quest_list_ids?: number[]`.
  - `Run` += `buff_potion_ids: number[]`, `rage_potion_ids: number[]`, `max_rage_potions: number`; create request the same (optional).
  - participant progress += `rage_potions_used?: number`.
  - quest-list participant progress += `quests_deferred?: number`,
    `deferred?: Record<number, 'level_too_low' | 'prerequisite_pending'>`,
    `skipped_by_reason?: Record<string, number>`, `pass?: number`,
    `retrying?: number[] | null`.
- New `potion.ts` — `Potion { id; game_item_id; name; image: string | null; level; player_bound; description; kind: 'buff' | 'rage' | 'other'; duration_minutes: number | null; rage_amount: number | null }`, `CharacterPotion { potion_id; potion?: Potion; quantity; active; active_until; active_synced_at; last_activated_at; refused_until; synced_at }`, `PotionActivationResult { outcome: 'activated' | 'already_active' | 'out_of_stock' | 'refused'; message; data: CharacterPotion | null }`.
- `run-event.ts` — `RunEventType` += `'potion_used' | 'potion_skipped' | 'quest_deferred'`.
- `world.ts` — mob search item += `spawnable: boolean`.
