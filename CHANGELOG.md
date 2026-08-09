# cdca-api Changelog

Notes for API consumers (Angular/mobile clients). Newest first.

## 2026-08-09 — Login host fix (`www` → apex)

Outwar started 301-redirecting `www.outwar.com` to `outwar.com`. A POST cannot
follow a 301 (the body is dropped), so **every `POST /api/v1/rgas/{id}/login`
was failing** with "Login did not redirect into the world (HTTP 301)". The
login host now defaults to `https://outwar.com` (override:
`OUTWAR_LOGIN_HOST`). No request/response contract change.

Two clearer failure messages for clients to surface:

- `The game rejected the username or password.` — the game bounced the login
  back to `/login?LE=1`. Previously reported as a missing-cookie error.
- `The login host … now redirects to … — point OUTWAR_LOGIN_HOST at the new
  host.` — so a future host move is diagnosable from the error alone.

## 2026-08-09 — Smart questing (auto-equip, loss-driven level-ups)

### API contract changes (client-facing)

**`POST /api/v1/runs`** (mob, quest and quest-list modes) — new optional field:

| Field | Type | Notes |
|---|---|---|
| `smart` | boolean, default false | Low-level survival mode. Auto-equips the best backpack item per slot (respecting each item's required level), levels up after a lost battle, and abandons a mob after 3 straight losses instead of grinding rage to zero. |

`smart` round-trips in the run's `config` object like `level_up`/`drop_junk`,
and a quest-list run passes it down to every quest and mob farm it spawns.
The CLI equivalent is `php artisan outwar:run-start … --smart`.

**New run outcome**: a participant stopped by the loss-streak rule finishes as
`stopped` with a `last_activity` of `Outmatched by {Mob} — stopping to preserve
rage.` This is terminal — unlike a rage-out it is never re-dispatched, because
waiting brings neither exp nor gear. Clients showing run history should treat
"outmatched" as "the character is too weak for this target yet".

Runs without `smart` behave exactly as before.

## 2026-07-24 — Items domain, security answer, junk-drop, trustees

### API contract changes (client-facing)

**`POST /api/v1/rgas`** — new optional field:

| Field | Type | Notes |
|---|---|---|
| `security_answer` | string ≤255, nullable | The RGA's security-question answer ("What is your favorite movie?"). Stored encrypted, never returned by the API. Required for the junk-drop feature to work. |

**RGA resource** (`GET /api/v1/rgas`, `/api/v1/rgas/{rga}`) — new field:

| Field | Type | Notes |
|---|---|---|
| `has_security_answer` | boolean | Whether an answer is stored. Use this to enable/disable the junk-drop toggle in the UI — the answer itself is never exposed. |

**`POST /api/v1/runs`** (mob mode) — new optional field:

| Field | Type | Notes |
|---|---|---|
| `drop_junk` | boolean, default false | End-of-run sweep that deletes known junk items from the character's backpack. Silently skipped (with a run-log line) when the RGA has no `security_answer` — the client should surface this dependency (e.g. disable the toggle when `has_security_answer` is false). |

`drop_junk` round-trips in the run's `config` object like the other mob
options (`mob_names`, `stop_rage`, `max_kills`, `level_up`).

No endpoints were removed or renamed; all other request/response shapes are
unchanged.

### Behavior changes (server-side, no client action needed)

- **Junk sweep**: with `drop_junk` on, the mob run ends with a backpack scan
  that deletes items matching a seeded junk list (347 names from the xOWH
  seed). Dropped items appear in the run log ("Dropped junk: {name}").
- **Session-death detection widened**: besides the login-page sentinel, the
  engine now recognizes the ajax "You must be logged in to view this page"
  error box and marks the RGA invalid. Clients may see RGA `status` flip to
  `invalid` more promptly.
- **Movement resilience**: the game's "Error moving rooms" rejection is now
  treated as recoverable (reload + re-plan) instead of aborting as a gated
  room; runs fail less on transient desyncs.
- The engine can teleport a character to a bar (`world.php?teleport=1`,
  re-verified) — used internally for leveling; not yet exposed via API.

### New game knowledge relevant to future client features

- **Backpack**: 6 tabs (`regular`, `quest`, `orb`, `potion`, `key`,
  `utility`); items expose name + stack qty directly; capacity =
  `maxSlots`/`itemCount` (maxSlots −1 = uncapped). Equipped gear is separate
  (equipment page), not part of the backpack list.
- **Item detail**: name, equip slot, `[Required Level N]`, stat block
  (ATK/HP/elemental/resists/crit/rampage/rage-hr/exp-hr/max-rage) with
  enhancement bonuses, and a daily trade limit. A backpack/equipment client
  view has everything it needs server-side; API endpoints for it don't exist
  yet.
- **Security question**: an intermittent anti-hijack challenge on sensitive
  actions (item deletion, trades, some skill actions). Handled entirely
  server-side via the stored answer; the client only needs the
  `has_security_answer` flag.
- **Trustees** (not yet in the API): a character from another RGA can be
  shared with yours for limited control (move, join raids, …). The game
  enumerates them alongside your own characters. A future `is_trustee` flag
  on the character resource is planned once trustee control is captured —
  clients should not assume every listed character will be fully controllable
  forever.
- **Skill reset** happens via a consumable item (no page/endpoint); school
  choice is treated as permanent for now. The game's "Auto Skiller"
  batch-cast is a paid feature and is deliberately not used — casting stays
  per-skill.

### Ops / deployment

- Run `php artisan migrate` (two new migrations: `rgas.security_answer`,
  `junk_items` table).
- Seed the junk list: `php artisan db:seed --class=JunkItemSeeder`
  (idempotent; also part of `DatabaseSeeder`).
- CLI `outwar:rga-add` now prompts for the (optional) security answer.

### Internal additions (for reference)

- `App\Game\Items\BackpackService` (contents/itemDetail/equip/unequip/delete)
  and `JunkDropper`; parsers `BackpackContentsParser`, `ItemRolloverParser`,
  `TrusteeListParser`; DTOs `BackpackItem`, `BackpackContents`, `ItemDetail`,
  `JunkDropSummary`, `TrusteeListEntry`; `Navigator::teleportToBar()`;
  `JunkItem` model + seeder. All fixture-tested against real captures
  (`tests/Fixtures/game/`). Reverse-engineering docs updated in
  `docs/game-api/` (items.md `[CHANGED]` for backpack markup, trustee model
  in auth-session.md).
