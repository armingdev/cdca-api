# Frontend onboarding — read this first

You are building the **client** (Angular web app, mobile later) for **cdca**, an
automation platform for the browser MMORPG Outwar. A Laravel backend already
exists and is done + tested. Your job is the UI that consumes its REST API. You
never talk to Outwar directly — only to this backend.

## 1. The one document that matters

**Read `api-reference.md` (in this same folder) in full before writing any
code.** It is the complete, authoritative contract: base URL, auth, every
endpoint with request/response JSON, all enum values, error formats, pagination,
the live-update (polling) model, and a "not yet available" list. Everything the
client needs is there. If this onboarding and the reference ever disagree, the
reference wins.

If you're working in a **separate frontend repo** and can't see this file, ask
the user to copy `api-reference.md` into your repo (e.g. `docs/api-reference.md`)
and read it there.

## 2. What this app does (30-second mental model)

- A **User** logs in and registers **RGAs** (Outwar game accounts). Each RGA
  holds many **Characters** across two servers (sigil / torax).
- The user starts **Runs** — automation jobs (modes: `mob`, `quest`,
  `quest-list`, `pvp`) applied to a selected set of characters. Each character
  in a run gets a background worker with live **status + W/L/E tallies + a
  last-activity log line**.
- Supporting data: a **Skill** catalog (per-character "cast on start"
  selection), **QuestLists** (named ordered quests), **World** rooms/mobs, and
  a **BattleEvent** log powering stats/drops.

The UI mirrors the reference desktop tool: a **fleet grid** of characters with
live columns, a **run control panel** per mode, and **log/stats** views.

## 3. Ground rules (design the client around these)

1. **Auth = Bearer token.** `POST /api/v1/login` returns a token; send
   `Authorization: Bearer {token}` + `Accept: application/json` on every call.
   Store the token; `401` means re-auth.
2. **Live data = polling, not websockets.** There is no socket layer yet. The
   backend writes run/character state to the DB in real time, so poll
   `GET /api/v1/runs/{id}` (and `/characters`) every 2–3s while a run screen is
   open; stop polling when a run's `status` is `completed`/`stopped`/`failed`.
   Isolate this in one polling service/hook so it can later be swapped for
   websockets without touching components.
3. **Single resources are wrapped in `data`; collections in `data[]` with
   `links`/`meta` when paginated.** Model your TS types accordingly.
4. **Enums are fixed strings** (run mode/status, skill school, battle
   kind/outcome) — see the reference §3. Type them as string-literal unions.
5. **Respect the "Not yet available" list** (reference §11: PvP scouting grid,
   items/inventory, named PvP lists, raids, websockets). Stub, hide, or feature-
   flag these — don't build hard dependencies on them.

## 4. Explore the live API to confirm shapes

Ask the user to start the backend (`sail up -d`, then `sail artisan horizon`
for run workers). Then verify against reality — don't guess response shapes:

```bash
# base = http://localhost/api/v1  (confirm the host with the user)
curl -s -X POST http://localhost/api/v1/register \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"Dev","email":"dev@example.com","password":"password123","password_confirmation":"password123"}'
# → { "user": {...}, "token": "1|..." }

TOKEN=... # paste the token from above
curl -s http://localhost/api/v1/skills?school=ferocity \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

Generate TS interfaces from actual responses, not from prose. `GET /skills` and
`GET /world/mobs` need no game session and are the easiest to probe.

## 5. Suggested build order

1. **Auth shell** — register/login/logout, token storage, an HTTP interceptor
   that adds the Bearer header and handles `401`/`422`.
2. **Accounts** — list/add RGAs, the login + sync-characters onboarding flow.
3. **Fleet grid** — `GET /characters` with the live columns (poll).
4. **Run control + monitor** — the `POST /runs` form (mode-driven fields per
   reference §8) and a run detail screen that polls `GET /runs/{id}` for
   per-character tallies + `last_activity`; a **Stop** button.
5. **Skills** — catalog + per-character cast-on-start selector; **Quest lists**
   CRUD; **Stats** (`/characters/{id}/stats` + `/battles`).

## 6. Things to ask the user if unsure

- The API base URL / host for their environment (local Sail vs. deployed).
- Whether an RGA is already logged in + characters synced (so live screens have
  data), or whether you should build the onboarding flow first against an empty
  state.
- Design/framework preferences (component library, state management) — the API
  is agnostic to all of it.

That's everything. Start with `api-reference.md`, probe the live API for exact
shapes, and build in the order above.
