# Teleports — implementation plan

> **Status: implemented 2026-08-17** (all seven steps), plus run-movement
> integration: `MobRunner` and `QuestRunner` route with the character's *free*
> anchors via `TeleportPlanner::planToNearest()`, never spending rage or the
> skill cooldown. Verified live on two characters. Client-facing notes in
> `api-changes-teleports.md`.

Branch: `feature/add-teleports`. Mechanics reference:
`/Users/armingerina/Code/cdca/docs/game-api/teleports.md` (VERIFIED 2026-08-16).
Captures: `docs/game-api/samples/teleport_item_landings.json`,
`teleport_items_capture.json`; fixtures `samples/fixtures/backpack_key_tab.html`,
`skills_info_teleport_destinations.html`.

## Goal

Teleports become first-class graph edges so that (a) the spider can enter areas
no walk reaches, and (b) pathfinding uses free item jumps instead of long
walks. Everything is **per character** — the skill must be trained on that
character, and the item anchors it owns depend on its level and quest
progression. Seed nothing globally; discover per character and let the union
across users grow the picture.

## The four mechanisms (recap for implementers)

| Mechanism | Request | Cost | Availability |
|---|---|---|---|
| Start hatch | `GET /world?room=1` | free | always (`Navigator::resetToStart`, exists) |
| Home tavern | `GET world.php?teleport=1` | free | always; re-home with `world.php?teleportupdate=1&tavern={roomId}` (link in room blob `tavernData`) |
| Teleport skill (27) | `POST /cast_skills` `dest={roomId}&castskillid=27&cast=Cast Skill` → 302 | **100 rage, 60 min cooldown** | only if trained; 17 destinations from `skills_info.php?id=27` |
| Teleport item | `POST /ajax/backpack_action.php` `action=activate&itemids[]={iid}` | **free, no cooldown, not consumed** | one anchor per owned item (28 on the reference char) |

Item activation answers `{"status":"{Item} activated!<br>","redirectTo":"\/world"}`
and the move is immediate — **do not** follow `redirectTo`; just call
`ajax_changeroomb.php?room=0&lastroom=0`.

## Step 1 — Schema

Three migrations (`php artisan make:migration`), all room FKs to `rooms.id`
(= game room id; the table is keyed by game id already):

**`teleport_anchors`** — the *global, learned* catalog. One row per known
teleport source, shared across characters; rows are created the first time any
character reveals one.

| column | notes |
|---|---|
| `id` | pk |
| `kind` | enum `item` \| `skill` \| `tavern` \| `hatch` |
| `game_item_id` | nullable — catalog item id (`data-itemid`), unique per `kind='item'` |
| `skill_id` | nullable — FK `skills.id` (27 for Teleport) |
| `name` | "Astral Ward" / "Sewers Entrance" |
| `room_id` | nullable FK `rooms.id` — landing room, **null until observed** |
| `required_level` | nullable, from the rollover `[Required Level n]` |
| `rage_cost`, `cooldown_minutes` | 0/0 for items, 100/60 for the skill |
| `description` | rollover prose ("Teleports you to the Astral World.") |
| `source` | `capture` \| `observed` |
| `first_seen_at`, `last_verified_at` | |

`room_id` is nullable on purpose: an item's rollover names an *area*, not a
room, and the prose often disagrees with the room name (Key to Industrial
District → **Cross Roads** 8178). Only an observed `curRoom` may fill it.

**`character_teleport_anchors`** — what *this* character can actually use.

| column | notes |
|---|---|
| `character_id`, `teleport_anchor_id` | composite unique |
| `iid` | nullable — the character's item **instance** id for `itemids[]` (per-character, changes if re-acquired) |
| `is_available` | recomputed each sync (item still in the key tab / skill still trained) |
| `last_used_at`, `synced_at` | |

**`characters`**: add `home_tavern_room_id` (nullable FK `rooms.id`).

Models: `TeleportAnchor`, `CharacterTeleportAnchor` (+ `Character` relations),
factories for both.

## Step 2 — Parsers (fixture-tested, per the guardrail)

1. **`BackpackItem::canActivate()`** — `str_contains($this->menuFlags, 'a')`.
   Trivial addition next to `canEquip()`; `BackpackContentsParser` already
   captures `menuFlags`. Also add `gameItemId` to `BackpackItem` — the parser
   currently drops `data-itemid`, and that is the stable catalog key we need
   (instance `iid` is not stable). Both need the regex/DTO touched.
   Fixture: `backpack_key_tab.html` — expect 42 items, 28 `canActivate()`,
   14 not (`cvs` carry-only gating keys).
2. **`TeleportDestinationsParser`** — `skills_info.php?id=27` →
   `list<TeleportDestination{roomId, name}>` from `<select name="dest">`.
   Fixture: `skills_info_teleport_destinations.html` — 17 options, expect
   `134 => 'Sewers Entrance'`, duplicate names on 231/299 must both survive
   (key by room id, never by name).
3. **`ItemRolloverParser`** — extend to expose `activatable` (the literal
   `click to activate`) and the `Teleports you to {…}` sentence; already
   parses `[Required Level n]`.
4. **Room blob** — `RoomBlobParser` must expose `tavernData`'s
   `tavern={roomId}` (a room is a tavern iff non-empty) so taverns get
   recorded as they are walked past.

## Step 3 — Services

**`app/Game/World/TeleportService.php`** (per character, mirrors
`Navigator::forCharacter`):

- `syncAnchors(): TeleportSyncResult` — read `backpackcontents.php?tab=key`,
  take `canActivate()` items, upsert `teleport_anchors` by `game_item_id`
  (rollover fills name/description/required_level on first sight only), upsert
  `character_teleport_anchors` with the current `iid`, and mark rows absent
  from the tab `is_available = false`. Then, **if the character has skill 27
  trained** (`character_skills`), read `skills_info.php?id=27` and upsert one
  `kind='skill'` anchor per destination (these already know their `room_id`).
- `useItem(TeleportAnchor $a): RoomBlob` — POST activate with the character's
  `iid`, assert the response `status` contains `activated`, then
  `Navigator::loadCurrentRoom()`. **Persist `room_id` on the anchor from the
  observed `curRoom`** when it was null (this is how the global catalog fills
  in), and reconcile/flag a mismatch if it was already set.
- `castTeleport(int $destRoomId): RoomBlob` — rage/cooldown preflight against
  `character_skills` (`recharge_until`, `current_rage_cost`), POST
  `cast_skills` with `dest`, then load the room and verify `curRoom === dest`.
- `toHomeTavern(): RoomBlob` / `setHomeTavern(int $roomId): void` — wrap
  `teleport=1` / `teleportupdate=1&tavern=`; the latter writes
  `characters.home_tavern_room_id`.

Reuse `GameClient` (throttled, per-character cookie jar) throughout; no new
HTTP path. `Navigator::teleportToBar()` stays as the low-level call and gets
delegated to from `toHomeTavern()`.

## Step 4 — Teleport-aware pathfinding

`RoomGraph` stays a pure walk graph. Add
`app/Game/World/TeleportPlanner.php` which composes it:

```
plan(from, to, availableAnchors): TeleportPlan
    best = shortestPath(from, to)                       // walk-only baseline
    for each anchor a with a.room_id known and usable:
        candidate = 1 + |shortestPath(a.room_id, to)|    // 1 = the jump
        if candidate < best: best = (jump a, then walk)
    return the winner
```

- Cost model: item jump = 1 (free/instant), skill jump = 1 but only when rage
  ≥ cost and off cooldown **and** the walk baseline is much worse (it burns a
  60-minute cooldown — make the threshold configurable, default "only if it
  saves ≥ 50 steps"), home tavern = 1.
- Anchors whose `room_id` is still null are unusable for planning but are
  candidates for *discovery* (step 5).
- `TeleportPlan { ?anchor, list<int> walkPath }`; an executor method on
  `Navigator`/`TeleportService` runs jump-then-walk. Unit-test the planner
  against a small synthetic graph — no HTTP.

## Step 5 — Spider integration (`MapCommand`)

- New option `--teleports` / `--anchor={game_item_id}`: teleport in, spider the
  reachable component, then `resetToStart()` (or the next anchor).
- On sync, any anchor with `room_id = null` is a **discovery target**: activate
  once, record the landing room, continue. That is exactly how the 28 rows in
  `teleport_item_landings.json` were produced, and it is safe to repeat (free,
  not consumed).
- Record the landing room through the existing `RoomObservationRecorder` so it
  flips `source` from `seed` to observed like any walked room.

Context: all 28 landing rooms and 17 skill destinations already exist in the
seeded `rooms` table, and BFS from those 45 anchors reaches **37,008 / 41,025**
seeded rooms. The spider is verifying, not discovering ids.

## Step 6 — Seeding from the capture (optional, cheap)

A `TeleportAnchorSeeder` can preload `teleport_anchors` from
`teleport_item_landings.json` (28 items with landing rooms) + the 17 skill
destinations, `source='capture'`, idempotent, **never** writing
`character_teleport_anchors`. It only saves the first character one discovery
pass; the per-character sync remains the source of truth. Follow the existing
seeder convention: never clobber `source='observed'` rows.

## Step 7 — API surface (after the engine works)

- `GET /api/characters/{id}/teleports` — the character's usable anchors
  (name, destination room/area, cost, availability).
- `POST /api/characters/{id}/teleports/{anchor}` — jump now.
- `POST /api/characters/{id}/home-tavern` — set home tavern.
- Include anchor usage in run summaries so the client can show "teleported to
  X, walked N rooms".

## Tests (Pest, `./vendor/bin/sail test`)

| Test | Covers |
|---|---|
| `BackpackContentsParserTest` (extend) | `a` flag → `canActivate()`, `gameItemId` parsed; 28/14 split on `backpack_key_tab.html` |
| `TeleportDestinationsParserTest` | 17 options, duplicate names, ids as ints |
| `TeleportServiceTest` | faked `GameClient`: activate POST body shape, `status` assertion, landing room persisted onto a null `room_id`, mismatch flagged |
| `TeleportServiceSkillTest` | rage/cooldown preflight blocks the cast; `dest` sent; desync on wrong `curRoom` |
| `TeleportPlannerTest` | synthetic graph: picks jump when it beats the walk, ignores unknown-`room_id` anchors, respects the skill threshold |
| `TeleportSyncTest` | items removed from the key tab → `is_available=false`; new item → anchor row created once, `iid` updated per character |

## Order of work

1. Migrations + models + factories (step 1)
2. Parser changes + their fixture tests (step 2)
3. `TeleportService` + tests (step 3)
4. `TeleportPlanner` + tests (step 4)
5. Wire into `MapCommand` (step 5), optional seeder (step 6)
6. API endpoints (step 7)

## Open questions (do not block implementation)

- How anchors are acquired (quest/drop/shop) — needed only when the engine
  should *obtain* a missing anchor.
- Failure shape when a level gate is unmet (all keys succeeded on a level-95
  character) — handle defensively: treat a `status` without "activated" as a
  failed jump and re-read the room.
- Whether `dest` accepts arbitrary room ids. **Do not fuzz this on a live
  account** without the user's explicit go-ahead.
