# API Changes — Teleports (2026-08-17)

Audience: the Angular client repo. **All changes are additive** — nothing
existing changed shape or semantics, so the client keeps working untouched.
Full contract in `api-reference.md` §5 ("Per-character teleports").

## TL;DR for the client

- Four new endpoints under `/characters/{id}`: list anchors, sync them from the
  game, travel, set the home tavern.
- The character payload gained one field: **`home_tavern_room_id`** (nullable).
- **Anchor availability is per character.** Item anchors depend on level and
  quest progression; the skill anchor needs the Teleport skill trained on that
  character. Never share or cache one character's list for another — a level-95
  character can have 28 item anchors and no skill anchor, while a level-77 one
  has 14 items plus 17 skill destinations (both observed live).
- **Runs now route via teleports automatically** — no run config, no request
  change, no new fields. Mob and quest runs use the character's *free* item
  anchors whenever that beats walking, and never spend rage or the Teleport
  skill's cooldown. A character with no synced anchors behaves exactly as
  before. The only visible effect: `last_activity` on a participant can now
  read `Teleporting with {anchor} (saves the walk).` — so the run log needs no
  special handling, but "sync this character's anchors" is worth surfacing as
  a way to speed runs up.

## What a teleport is

Three kinds of fast travel, all landing the character in a room:

| Kind | Cost | Notes |
|---|---|---|
| `item` | **free** | No cooldown, not consumed — reusable as often as you like |
| `skill` | 100 rage, 60 min cooldown | Only if the character trained the Teleport skill |
| home tavern | free | One per character, re-homeable to any tavern it has reached |

## Endpoints

### `GET /characters/{id}/teleports`

```json
{ "data": [ { "anchor_id": 5, "name": "Astral Ward", "kind": "item",
  "game_item_id": 4839, "room_id": 26137, "room_name": "Astral Rift",
  "description": "Teleports you to the entrance of the Astral World.",
  "required_level": null, "rage_cost": 0, "cooldown_minutes": 0,
  "free": true, "destination_known": true, "available": true,
  "last_used_at": null, "synced_at": "2026-08-17T09:58:02Z" } ] }
```

Two flags drive the UI:

- **`destination_known: false`** — we have never seen where this anchor lands
  (`room_id` is `null`). Render it as "unknown destination"; it resolves the
  first time the anchor is used. Do not hide it — using it is how it is learned.
- **`available: false`** — the character no longer holds it. Kept for history;
  don't offer it as an action.

`description` is the game's own prose and often names a broader *area* than the
landing room ("Key to Industrial District" lands in "Cross Roads"). When both
are present, `room_name` is the truth.

### `POST /characters/{id}/teleports/sync`

Re-reads the character's key tab and, when Teleport is trained, the skill's
destination list. Costs ~30 throttled game requests, so treat it like the skill
sync: a manual "Refresh" button, not something polled.

```json
{ "message": "31 teleport anchor(s) available.", "item_anchors": 14,
  "skill_anchors": 17, "discovered": 0, "unavailable": 0,
  "without_destination": 0, "anchors": [ … ] }
```

- `discovered` — anchors new to the whole catalog (first time any character has
  shown us this item).
- `unavailable` — anchors this character lost since the last sync.
- `without_destination` — how many of its anchors still have an unknown landing
  room; worth surfacing as "N anchors with unknown destination".

### `POST /characters/{id}/teleports` — travel

Send exactly one of:

| Body | Behavior |
|---|---|
| `{ "room_id": 26152 }` | The API plans the cheapest route (jump + walk, or plain walk) and executes it |
| `{ "anchor_id": 5 }` | Jump with that anchor, no walking |
| `{ "home_tavern": true }` | Free return to the home tavern |

```json
{ "message": "Arrived in Astral Rift (room 26137).", "room_id": 26137,
  "room_name": "Astral Rift", "teleported": true, "anchor": "Astral Ward",
  "steps_walked": 1 }
```

Routing rules worth reflecting in the UI: free item jumps are taken whenever
they beat walking, and on a tie the free anchor always wins over the skill. The
skill is only spent when it saves a lot of walking or is the only way in — so
a "travel here" button will rarely burn the 60-minute cooldown by surprise.

`422` with a plain `message` when: the anchor isn't usable by this character,
the skill is untrained / on cooldown / unaffordable, or no route exists
(`"No route from room 1 to room 999, with or without a teleport."`).

**This call is synchronous and can take a while** — one game request per walked
room, throttled. Expect seconds for a long walk; show a spinner and disable the
control rather than letting the user queue several. `steps_walked` afterwards
tells you how far it actually went.

### `POST /characters/{id}/home-tavern`

`{ "room_id": 376 }` → `{ "message": "Home tavern set to room 376.",
"home_tavern_room_id": 376 }`. Must be a tavern room the character has reached.
The value also rides on the character payload, and it is filled in
automatically the first time the character teleports home.

## Character payload

One new nullable field:

```json
{ "id": 5, "name": "RealLinuXX", "current_room_id": 258,
  "home_tavern_room_id": 376, "status": "running", … }
```

`null` = never set, so the game default applies (Dusty Glass Tavern, room 258).

## Suggested UI

A "Travel" panel on the character screen: the anchor list grouped by
`kind`, each row showing `room_name` (or "unknown destination") and the cost
badge (`free` vs `100 rage / 60m`), a "Refresh anchors" button hitting `sync`,
and a room-id input that posts `{ room_id }`. `GET /world/rooms/{roomId}`
already exists if you want to preview the destination room and its exits.
