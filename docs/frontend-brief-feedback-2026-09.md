# Frontend brief — tester feedback batch (API, 2026-09-19)

Audience: a Claude session working in `/Users/armingerina/Code/cdca/cdca-client`
(Angular 22, standalone components, signals + `@angular/forms/signals`, Vitest).

A tester sent a list of bugs and wishes. The API side is done; this is what
the client needs to do to finish each one. **Nothing in the client breaks if
none of this is done** — every API change is additive, and the one renamed
config key (`crew_game_ids`) still mirrors the old one.

Work order suggestion: §1 (bugs the tester hit), then §2 (small wins), then §3.

---

## 1. Bugs the tester reported

### 1.1 "Accounts are jumping all over the place, like the list is still loading"

Root cause was server-side: `GET /characters` ordered by `level` only, and
Postgres returns tied rows in whatever order they were last written. Every
status/stats write moved a character, so a fleet of same-level alts reshuffled
on each 2.5 s poll. **Fixed in the API**: the order is now total
(`level desc, name, id`). `/rgas`, `/runs`, `/quest-lists`, `/attack-lists`
got an `id` tie-break too.

Client follow-up (defence in depth, small):

- `features/fleet/fleet-page.ts` — `compare()` sorts on a single key with no
  tie-break, so any *client-side* sort (by status, by crew, by rage…) still
  reorders tied rows when their input order changes. Add `|| a.id - b.id` as
  the final comparator for every sort key.
- Same for any other list that re-sorts polled data (the character picker on
  `features/runs/new-run-page`).

### 1.2 "Stop / pause should apply instantly"

Two halves:

- **API**: a stop or pause now lands between two room steps instead of after
  the whole walk, so a live participant reacts within a request or two.
- **Client**: the run and its participants go to `stopping` / `pausing` the
  moment the request returns, and only become `stopped` / `paused` when the
  worker lands. Render `stopping` / `pausing` as their own visible state
  ("Stopping…", spinner, buttons disabled) **from the response of the
  stop/pause call**, not from the next poll. Today the UI looks idle until the
  poll catches up, which reads as "nothing happened".

### 1.3 "Getting a lot of `App\Jobs\RunQuestListJob has been attempted too many times`"

Fixed at the root in the API (workers were being OOM-killed; see the API
changelog). What changes for the client:

- That message should no longer appear. A participant whose worker dies now
  parks as `waiting` with `last_activity` = "The worker driving this run died.
  Resuming shortly." / "…stopped responding. Resuming shortly." and resumes by
  itself. Network trouble parks as "Connection trouble — retrying at HH:MM: …".
  These are **not errors** — style them like any other `waiting` park.
- New `progress` counters (all optional, all reset to 0 after a clean pass):
  `worker_deaths` (fails at > 3), `transient_failures` (fails at > 5). Add
  them to `RunProgress` in `core/models/run.ts`. `parkReason()` can map the two
  messages above to a "recovering" reason if you want a distinct chip.
- Skill-cast warnings: a refused skill now backs off (5 min → 1 h → 24 h)
  instead of warning every pass. `skill_cast` event context gains the skip
  reason `backing_off`; `skill_cast_failed` context gains `refusals` and
  `retry_at`. Add `backing_off` to the skip-reason union in
  `core/models/run-event.ts`.
- **Teleport (skill id 27) can never be cast as a buff** — the engine now
  ignores it in a selection. Hide it (or disable it with a hint) in the
  cast-on-start skill pickers.

---

## 2. Small wins

### 2.1 Trustee filter on the fleet

New field on every character: `is_trustee: boolean`. New query parameter:
`GET /characters?ownership=own|trustee` (omit for both).

- Add `is_trustee` to `Character` in `core/models/character.ts`.
- Fleet page: next to the All / Sigil / Torax segmented control, add
  All / Mine / Trustees. The fleet page filters client-side already, so filter
  on `c.is_trustee` rather than re-querying. Default to **Mine** — the tester's
  complaint is that level-95 trustees crowd out his own characters.
- Show a small "trustee" chip on trustee rows, and apply the same filter in
  the new-run character picker.
- `features/../shared/game-links.ts` has a comment saying trustees never reach
  the fleet. That was wrong (accounts.php lists them); update it.

The flag is set by the roster sync, so existing characters get it on the next
"Sync characters" of their account.

### 2.2 Copy: "Security answer (optional)"

`features/accounts/accounts-page.html:58` — the tester read "secret is
optional" as "encryption of my password is optional". Rename the label to
**"Secret pass (optional)"** and keep the hint below it explicit: what it is
(the answer to the account's security question), what it is for (the
junk-drop run option), and that it is stored encrypted. Same wording in the
table header (`:119`) and the edit panel (`:340`).

### 2.3 Run names

- `POST /runs` accepts `name` (string, max 80, optional).
- `PATCH /runs/{id}` with `{ "name": "…" | null }` renames (owner only).
- `name` is on every Run resource (`null` when unnamed).

Client: a "Name (optional)" field at the top of the new-run form; show the
name as the primary label in `features/runs/runs-page` with `#id · mode` as the
secondary line (fall back to today's rendering when `name` is null); inline
rename on the run detail page. Add `name: string | null` to `Run` and
`name?: string` to `StartRunBase`.

### 2.4 Single-quest mode: pick the quest by name

`POST /runs` in `mode: "quest"` now accepts **`catalog_quest_id`** (the
catalog row's `id`, as returned by `GET /quests`). The API derives the giver
and the game quest id from it. `npc` + `quest_id` are only required when
`catalog_quest_id` is absent, and stay available for quests the catalog does
not know.

Client: in `features/runs/new-run-page.html:226-250`, replace the two raw
inputs with the typeahead that `features/quest-lists/quest-lists-page.ts:60-76`
already has (`questsApi.list({ search, per_page: 10 })`, 250 ms debounce,
2-char minimum). Send `catalog_quest_id: quest.id`. Keep an "enter manually"
disclosure with the old two inputs.

Trap: the catalog `id` and `game_quest_id` are different numbers. With the
typeahead send `catalog_quest_id = quest.id`; the old `quest_id` field means
the *game* id. A quest with no known giver answers 422 on `catalog_quest_id`
("The catalog does not know who gives … yet.") — show it inline.

---

## 3. New features

### 3.1 Quest lists: import, community lists, copy

| Endpoint | Purpose |
|---|---|
| `POST /quest-lists/import` | Build a list from a file or pasted text |
| `GET /quest-lists?scope=community` | Lists other users published + built-in lists |
| `POST /quest-lists/{id}/copy` | Copy any readable list into my lists |
| `PATCH /quest-lists/{id}` | Rename / publish (`name`, `is_public`) — owner only |

`QuestList` resource gained `is_public`, `is_mine`, and (community scope only)
`shared_by` (owner's display name, `null` for built-in lists). List names are
now unique **per user**, so a 422 on `name` only means *you* already have it.

**Import** — multipart `file` (≤ 256 KB, text or JSON; **the extension does
not matter**, `.dql` and `.txt` both work) *or* JSON `{ text, name }`:

- dDCT quest lists are JSON `{"Name": "75 Caverns", "QuestNames": [...]}`.
  `name` is optional for these (the file's own name is used, then the
  filename).
- Plain text is one quest per line, by **name or game quest id**; blank lines
  and `#` comments are ignored. `name` is required for pasted text.
- Response `201`: the usual `data` (with `items.quest` loaded) plus
  ```json
  "import": { "imported": 17, "unmatched": ["Some Renamed Quest"], "ambiguous": ["Aura EXP"] }
  ```
  `unmatched` = lines with no catalog quest; `ambiguous` = names more than one
  quest carries (the lowest-level one was used). Show both after the import so
  the user can fix them by hand.
- Importing the same list twice does not fail: the second becomes
  "75 Caverns (2)".
- `422 { message }` (no `errors` key — it is a domain error, not validation)
  when nothing in the file matches the catalog, a dDCT file has no
  `QuestNames`, or pasted text has no name.

Client: on `features/quest-lists`, an "Import" button (file picker + paste
tab), a "Community" tab listing `scope=community` with a "Copy to my lists"
action and a read-only detail view, and a "Publish" toggle on my own lists.
A run can only use **my** lists — copy first.

### 3.2 Cumulative drops

- `GET /stats/drops` — totals across the whole fleet. Filters: `from`, `to`
  (dates), `character_id`, `mob_id`, `run_id`; `group_by=drop` (default) or
  `mob`. Response (plain arrays, **no `data` envelope**):
  ```json
  { "drops": [ { "drop_name": "Amdir Potion", "count": 41 } ], "total": 41 }
  ```
  With `group_by=mob` each row also has `mob_id` and `mob_name` (both `null`
  for PvP drops).
- `GET /runs/{id}/drops` — the same shape, always grouped by mob, for one run.
- `BattleEvent` gained `run_id`. `GET /runs/{id}/battles` now returns **only
  that run's battles** (it used to return every battle its characters ever
  fought). Runs recorded before today fall back to "their characters' battles
  since the run was created".

Client: a "Drops" page (nav entry next to Runs) with a date-range picker, the
totals table, and a "by mob" toggle; a "Drops" section on the run detail page.
The per-character drops table on the character page is unchanged.

### 3.3 Multiple crews in a crew-members PvP run

- `POST /runs` (`mode: "pvp-crew-members"`) takes **`crew_game_ids`**: 1–10
  distinct game crew ids. The old `crew_game_id` is still accepted.
- `run.config.crew_game_ids` is the source of truth; `run.config.crew_game_id`
  mirrors the first id and is **deprecated** — stop reading it.
- `GET /crews?search=&server_id=&per_page=` — crews the app has seen (a crew
  appears after any run reads its roster), searchable by name fragment or game
  crew id. Fields: `id, server_id, server, game_crew_id, name, leader,
  total_members, average_level, members_synced_at`. Paginated, `per_page` ≤ 100.

Client: replace the bare number input at `new-run-page.html:402-424` with a
multi-select chip input backed by `GET /crews` (filter by the selected
characters' server), still allowing a raw id to be typed for a crew we have
never seen. Drop the "There is no crew search yet" hint.

### 3.4 Save rage before a brawl

New run options on **every mode except the two brawl modes**:

- `reserve_rage_for`: array of `"pvp-brawl"` | `"faction-brawl"` (default `[]`)
- `reserve_rage_hours`: 1–72 (default 12)

Both are echoed on the Run resource. When the chosen brawl on the character's
server is within that many hours, the participant parks as `waiting` with
`last_activity` = "Saving rage for the PvP Brawl (2026-09-21 00:00) — resumes
2026-09-21 12:00." and resumes by itself when the brawl window closes. The
`parked` run event carries `context.reserve_for`.

Client: two tick boxes in the scheduling section of the new-run form ("Save
rage before PvP Brawl" / "…Faction Brawl") and an hours input shown when
either is ticked; a `parkReason()` case for the message prefix "Saving rage
for". Gladiator / Envoy are **not available yet** — the API rejects any other
value with a 422, so do not render boxes for them.

---

## 4. Type changes checklist (`core/models`)

- `character.ts` — `Character.is_trustee: boolean`
- `run.ts` — `Run.name`, `Run.reserve_rage_for`, `Run.reserve_rage_hours`;
  `StartRunBase.name?`, `.reserve_rage_for?`, `.reserve_rage_hours?`;
  `StartQuestRun.catalog_quest_id?` (and `npc`/`quest_id` become optional);
  crew-members start request `crew_game_ids: number[]`;
  `RunConfig.crew_game_ids?: number[]`; `RunProgress.worker_deaths?`,
  `.transient_failures?`
- `run-event.ts` — skip reason `backing_off`; `skill_cast_failed` context
  `refusals`, `retry_at`; `parked` context `reserve_for`, `exception`, `attempt`
- `battle-event` — `run_id: number | null`
- `quest-list` — `is_public`, `is_mine`, `shared_by?`; import response type
- new: `Crew`, `DropTotal`, `DropTotalsResponse`
