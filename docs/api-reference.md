# cdca-api — REST API Reference (v1)

The backend for **cdca**, an automation platform for the browser MMORPG Outwar
(bots are explicitly permitted by the game's developers). This document is the
complete contract for building the Angular web client and mobile app. The API
owns all game-server communication; the client only ever talks to **this** API,
never to Outwar directly.

- **Base URL:** `{host}/api/v1` (e.g. `http://localhost/api/v1` in local Sail).
- **Format:** JSON in, JSON out. Always send `Accept: application/json`.
- **Auth:** Bearer token (Laravel Sanctum personal access tokens).
- **Prefix/versioning:** every route is under `/api/v1`. Future breaking changes
  ship as `/api/v2`.

---

## 1. Concepts & domain model

The object graph, top to bottom:

- **User** — you (the operator). Owns everything below. Authenticates to this API.
- **RGA** (Rampid Gaming Account) — one Outwar login. Holds up to 75 characters
  across both servers. Credentials are stored **encrypted** and never returned.
  An RGA must be *logged in* (session captured) before its characters can act.
- **Character** — one in-game character (`suid` + `server_id`). Belongs to an
  RGA. Servers: **1 = sigil, 2 = torax** (identical worlds, separate populations).
- **Run** — an automation job applied to one or more characters. Has a **mode**
  (`mob`, `quest`, `quest-list`, `pvp`) and a config. Each character in a run
  gets its own background worker (**RunParticipant**) with live status + tallies.
- **QuestList** — a named, ordered set of quests, run in sequence.
- **Skill** — the global catalog (43 skills). Per-character you select a
  "cast on start" set; the engine casts them when a run begins.
- **BattleEvent** — an append-only log of every attack (PvE + PvP): outcome,
  exp, gold, drop, opponent. Powers the stats/drops views.

**Live data model:** the background workers write status, tallies, and
`last_activity` to the database in real time. There is **no websocket yet** —
the client gets live updates by **polling** the run/character endpoints (a 2–3s
interval on an open run screen is the intended pattern). See §9.

---

## 2. Authentication

Token-based. Register or log in, store the returned `token`, and send it on
every subsequent request:

```
Authorization: Bearer {token}
Accept: application/json
```

### `POST /register`
Public. Rate-limited (10/min per IP).

Request:
```json
{ "name": "Armin", "email": "armin@example.com",
  "password": "secret1234", "password_confirmation": "secret1234",
  "device_name": "angular-web" }
```
`device_name` is optional (labels the token). `201 Created`:
```json
{ "user": { "id": 1, "name": "Armin", "email": "armin@example.com" },
  "token": "1|abcdef..." }
```

### `POST /login`
Public. Rate-limited (10/min per IP).
```json
{ "email": "armin@example.com", "password": "secret1234", "device_name": "angular-web" }
```
`200 OK` → same shape as register (`user` + `token`). Wrong credentials →
`422` with `{ "errors": { "email": ["These credentials do not match our records."] } }`.

### `POST /logout`
Auth required. Revokes the **current** token. `200 OK`.

### `GET /user`
Auth required. `200 OK` → `{ "user": { ... } }`.

**Unauthenticated requests to protected routes return `401`.**

---

## 3. Conventions

- **Single resources** are wrapped in `data`: `{ "data": { ... } }`.
- **Collections** are `{ "data": [ ... ] }`. Paginated collections also include
  `links` (`first/last/prev/next`) and `meta` (`current_page`, `last_page`,
  `per_page`, `total`, …). Paginated endpoints accept `?per_page=` and `?page=`.
- **Timestamps** are ISO-8601 UTC strings (e.g. `2026-07-16T18:40:00.000000Z`)
  or `null`.
- **Errors:**
  - `401` — missing/invalid token.
  - `403` — authenticated but not your resource.
  - `404` — not found.
  - `422` — validation failed: `{ "message": "...", "errors": { "field": ["..."] } }`.
  - `429` — rate limited (auth routes).
- **Enums** (exact string values):
  - Run `mode`: `mob` · `quest` · `quest-list` · `pvp`
  - Run/participant `status`: `pending` · `running` · `stopping` · `stopped` · `completed` · `failed`
  - RGA `status`: `active` · `invalid`
  - Skill `school`: `class` · `ferocity` · `preservation` · `affliction` · `misc`
  - Battle `kind`: `pve` · `pvp`; `outcome`: `win` · `loss` · `failed` · `unknown`

---

## 4. RGAs (game accounts)

### `GET /rgas`
Your RGAs, each with a `characters_count`.
```json
{ "data": [ { "id": 1, "username": "linuxx", "status": "active",
  "has_session": true, "characters_count": 12,
  "last_login_at": "…", "created_at": "…" } ] }
```

### `POST /rgas`
```json
{ "username": "linuxx", "password": "outwar-password" }
```
`201` → the RGA resource (no password/cookies ever returned). The password is
stored encrypted.

### `GET /rgas/{id}` · `DELETE /rgas/{id}`
Show / delete one of your RGAs. `403` if not yours.

### `POST /rgas/{id}/login`
Logs the RGA in to Outwar and captures its session. Synchronous (makes a live
game request). `200` → RGA resource with `has_session: true`. On failure →
`422 { "message": "…" }`.

### `POST /rgas/{id}/sync-characters`
Discovers all characters on the RGA (both servers) and upserts them. Auto-logs
in first if needed. `200` → `{ "data": [ Character, … ] }`.

**Onboarding flow:** create RGA → `POST /login` → `POST /sync-characters` →
characters appear under `GET /characters`.

---

## 5. Characters

### `GET /characters`
All your characters (across RGAs), ordered by level desc. Filters:
`?server_id=1|2`, `?rga_id={id}`.
```json
{ "data": [ { "id": 5, "rga_id": 1, "suid": 2403, "server_id": 1,
  "server": "sigil", "name": "RealLinuXX", "level": 95, "rage": 244093,
  "exp": 169548518310, "crew": "Collective 2", "current_room_id": 258,
  "status": "Attacking", "last_stats_at": "…" } ] }
```
`rage`/`exp`/`level`/`current_room_id`/`status` update live while a run is
active — poll this endpoint to drive the fleet grid.

### `GET /characters/{id}`
One character. `403` if not yours.

### Per-character skills

- `GET /characters/{id}/skills` — the character's cast-on-start selection with
  live buff/cooldown state:
  ```json
  { "data": [ { "skill_id": 3008, "skill": { "id": 3008, "name": "Circumspect",
    "school": "ferocity", "rage_cost": 20, "cooldown_minutes": 720,
    "duration_minutes": 60, "description": "…" },
    "cast_on_start": true, "last_cast_at": "…",
    "buff_active": true, "on_cooldown": true } ] }
  ```
- `PUT /characters/{id}/skills` — **replace** the cast-on-start set:
  `{ "skill_ids": [3008, 9, 4] }` → returns the new selection. Send `[]` to clear.
- `POST /characters/{id}/cast` — cast now. Either `{ "skill_id": 3008 }` (one
  skill) or `{ "on_start": true }` (the whole selected set). `200 { "message": … }`,
  or `422` if the cast was rejected (rage/cooldown/not learned).

### Character stats

- `GET /characters/{id}/battles?per_page=50` — recent battle events (paginated,
  newest first). Each item is a **BattleEvent** (§8).
- `GET /characters/{id}/stats` — aggregates for the Stats tab:
  ```json
  { "mobs": [ { "name": "Kix Harvester", "total": 2595, "wins": 2595, "losses": 0 } ],
    "drops": [ { "drop_name": "Kix Potion", "count": 255 } ] }
  ```

---

## 6. Skills catalog & World (read-only reference data)

- `GET /skills` — the 43-skill catalog. Filter `?school=ferocity`. Each item:
  `{ id, name, school, rage_cost, cooldown_minutes, duration_minutes, description }`.
  `id` is the game's cast id. Circumspect = `3008`, Street Smarts = `25`,
  Circle of Protection = `14`.
- `GET /world/mobs?q={name}&per_page=50` — search known mobs by name
  (paginated), for picking farm/attack targets. Each item includes `room_ids`.
- `GET /world/rooms/{roomId}` — a mapped room with its exits + mobs:
  ```json
  { "data": { "id": 11, "name": "Intersection",
    "exits": { "north": 12, "east": 41, "south": 40, "west": 10 },
    "is_gated": false, "gate_reason": null, "last_verified_at": "…",
    "mobs": [ { "id": 7, "name": "…", "level": 60, … } ] } }
  ```

*(The world is populated by the mapper. If it hasn't been run, these may be
sparse. That's a backend/ops concern, not a client one.)*

---

## 7. Quest lists

A quest list is a named, ordered set of quests, run in sequence by `quest-list`
mode. **Note:** because there is no quest catalog yet, each item carries the
giver **npc_name** + **quest_id** explicitly.

- `GET /quest-lists` — your lists with `items_count`.
- `POST /quest-lists` — `{ "name": "Armins List" }` → `201`.
- `GET /quest-lists/{id}` — the list with its ordered `items`:
  ```json
  { "data": { "id": 1, "name": "Armins List", "items_count": 2,
    "items": [ { "id": 10, "position": 1, "quest_id": 742, "npc_name": "Stella",
      "label": "Street Crawler", "display_name": "Street Crawler" } ] } }
  ```
- `DELETE /quest-lists/{id}` — delete the list.
- `POST /quest-lists/{id}/items` — append a quest:
  `{ "quest_id": 742, "npc_name": "Stella", "label": "Street Crawler" }`
  (`label` optional). Returns the full list.
- `DELETE /quest-lists/{id}/items/{position}` — remove the item at that
  1-based position; remaining positions close up. Returns the full list.

---

## 8. Runs — the automation engine

A run applies one mode + config to a set of your characters. Starting a run
immediately queues one background worker per character. Workers update their
participant row live.

### `POST /runs` — start a run
Common fields (all modes):
| Field | Type | Notes |
|---|---|---|
| `mode` | enum | `mob` · `quest` · `quest-list` · `pvp` (required) |
| `characters` | int[] | your character ids (required, ≥1). `422` if any isn't yours |
| `stop_rage` | int | rage-pool floor; stop below it (default 2500) |
| `level_up` | bool | level up (refills rage) instead of stopping when low |
| `cast_on_start` | bool | cast the characters' selected skills before the run |
| `require_circumspect` | bool | only run while Circumspect is active (else gated off) |
| `restart_every_minutes` | int? | re-dispatch this run every N minutes after it finishes |
| `start_at` | datetime? | delay the first start until this time (e.g. `"2026-07-16T22:57:00Z"`) |

Mode-specific fields:
| Mode | Fields |
|---|---|
| `mob` | `mobs`: string[] (exact mob names, required); `max_kills`: int (0 = unlimited) |
| `quest` | `npc`: string (giver name, required); `quest_id`: int (required) |
| `quest-list` | `quest_list_id`: int (required, must be yours) |
| `pvp` | `targets`: string[] (player names, required); `attack_rage`: int 2–50 (default 50); `attacks_per_target`: int (default 1); `message`: string? |

Example (mob mode, fleet of 2, cast skills + Circ gate):
```json
{ "mode": "mob", "characters": [5, 6], "mobs": ["Kix Harvester"],
  "max_kills": 0, "stop_rage": 2500, "level_up": true,
  "cast_on_start": true, "require_circumspect": true,
  "restart_every_minutes": 60 }
```
`201 Created` → the **Run** resource with `participants` embedded:
```json
{ "data": { "id": 12, "mode": "mob", "status": "running",
  "config": { "mob_names": ["Kix Harvester"], "stop_rage": 2500, "max_kills": 0, "level_up": true },
  "cast_on_start": true, "require_circumspect": true,
  "restart_every_minutes": 60, "start_at": null, "last_started_at": "…",
  "participants": [
    { "id": 30, "run_id": 12, "character_id": 5,
      "character": { "id": 5, "name": "RealLinuXX", "level": 95, … },
      "status": "running", "wins": 0, "losses": 0, "errors": 0,
      "last_activity": null, "started_at": "…", "finished_at": null } ],
  "created_at": "…" } }
```

### `GET /runs`
Your runs, newest first, each with `participants` (and their `character`). This
is the **fleet dashboard** feed.

### `GET /runs/{id}`
One run with live participants. **Poll this** (2–3s) while a run screen is open
to update per-character `status`, `wins`/`losses`/`errors`, and `last_activity`
(the movement/battle log line).

### `POST /runs/{id}/stop`
Graceful stop: every worker exits at its next loop iteration and auto-restart is
disarmed. `200` → the run (status → `stopping`, then `stopped` once workers exit).

### `GET /runs/{id}/battles?per_page=50`
All battle events across the run's characters (paginated, newest first).

**Participant status lifecycle:** `pending` → `running` → (`completed` |
`stopped` | `failed`). The run's own status settles to `completed` when all
participants finish, `stopped` if any was stopped, `failed` if any failed.
`last_activity` is the human log line (e.g. `"Beat Kix Harvester (+379 exp)"`).

---

## 9. BattleEvent shape

Returned by `/characters/{id}/battles` and `/runs/{id}/battles`:
```json
{ "id": 900, "character_id": 5, "kind": "pve", "outcome": "win",
  "mob_id": 7, "mob": "Kix Harvester", "opponent_name": null,
  "room_id": 258, "battle_id": 20070546825,
  "exp_gained": 1001, "gold_gained": 125, "drop_name": "Thief Dagger",
  "fail_reason": null, "occurred_at": "…" }
```
- `kind: "pve"` → `mob`/`mob_id`/`room_id` set, `opponent_name` null.
- `kind: "pvp"` → `opponent_name` set, `mob`/`room_id` null.
- `outcome: "failed"` → `fail_reason` explains why (stale target, contention…).

---

## 10. Live updates (polling)

There is **no websocket layer yet.** The workers persist state continuously, so
the client stays live by polling:

- **Fleet grid / dashboard:** poll `GET /runs` or `GET /characters` every 2–3s.
- **Open run detail:** poll `GET /runs/{id}` every 2–3s for per-character
  tallies + `last_activity`.
- Stop polling a run once its `status` is `completed`/`stopped`/`failed`.

A websocket push layer (Laravel Reverb) is a planned enhancement; when it lands
it will broadcast run/participant updates on a private per-user channel and this
doc will gain a §11. Build against polling for now — it's fully functional.

---

## 11. Not yet available (so the client can stub/hide these)

These backend features aren't built yet; don't design hard dependencies on them:

- **PvP scouting grid** — target Power/Ele/"Has SS Cast"/"COP Cast"/"Stripped"/
  level-cap flags and skip rules. PvP currently attacks by name without scouting.
- **Items / inventory** — auto-equip and auto-drop-junk; no item endpoints yet.
- **Named PvP target lists** and crew-roster/hitlist import — PvP targets are
  passed inline per run for now.
- **Raids / God raids.**
- **Scheduling niceties** — Circ-aligned `:57` starts and 12-hour fleet stagger
  (basic `start_at` + `restart_every_minutes` exist).
- **Websockets** (see §10).

Everything else in this document is implemented and covered by tests.
