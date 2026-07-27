# API Changes — Run System Overhaul (2026-07-27)

Audience: the Angular client repo. This documents everything that changed in
the cdca-api run/automation system across the six-phase overhaul: new
endpoints, new statuses and fields, changed semantics, and client behavior
that should be removed. All changes are additive unless marked **BREAKING /
semantics change**.

## TL;DR for the client

- Runs can now **pause/resume** and **self-resume on the Circumspect cycle**;
  build UI for the new `paused`/`waiting` states and the `resume_at` timestamp.
- Participants carry a `progress` object (kills, passes, quest-list position).
- **Rage-out is no longer `failed`.** Remove any "failed = rage out" handling.
- `characters.status` is now a real live activity column (`idle | running |
  waiting | paused`) — safe to render in the fleet grid.
- Stats refresh automatically on RGA connect and every ~15 min while idle;
  manual refresh endpoints exist for both a single character and a whole RGA.
- Mob (farming) runs accept `run_count` and `attack_interval_seconds`
  ("number of runs" and "attack interval" with pass semantics).
- New endpoints: pause/resume runs, delete runs, update RGA credentials,
  refresh stats ×2.

---

## 1. Run & participant statuses

`status` on both runs and participants now uses this vocabulary:

| status | on a run | on a participant |
|---|---|---|
| `pending` | scheduled (`start_at` in the future), not yet started | queued for a worker (initial start or resume re-dispatch) |
| `running` | at least one worker active | worker actively driving the character |
| `stopping` | stop requested, workers exiting | stop requested; worker exits at next loop iteration |
| `pausing` | *(participants only in practice)* | pause requested; worker parks at next loop iteration |
| `paused` | every non-finished participant is parked by the user | parked by the user; resumable |
| `waiting` | run is parked but **self-propelling** (auto-resumes) | parked by the engine; auto-resumes at `resume_at` |
| `stopped` | terminal | terminal |
| `completed` | terminal | terminal |
| `failed` | terminal (real errors only now) | terminal (real errors only now) |

Run-level aggregation (once no worker is in flight): `waiting` > `paused` >
`failed` > `stopped` > `completed`.

**Semantics changes (remove old client handling):**

- **Rage-out is never `failed` anymore.** Quest/quest-list runs that run out
  of rage end `stopped` (with the reason in `last_activity`), or park
  `waiting` when `require_circumspect` is set. Any client logic that treated
  `failed` as the normal end of a rage-limited run must go; `failed` now
  always means a real error (exception, login failure, lock conflict).
- **Circ-gated runs no longer die.** Previously a run with
  `require_circumspect` whose Circumspect was on cooldown ended `stopped`
  forever. Now it parks `waiting` with `resume_at` ≈ end of the Circ
  cooldown and resumes automatically — casting the configured skills again
  and continuing from persisted progress, cycling until the quest/list/pass
  count is done. Remove any client-side workaround (e.g. surfacing these as
  dead runs, or prompting the user to restart them).
- **Scheduled runs are `pending`, not `running`.** A run created with a
  future `start_at` reports `pending` until its first participant actually
  starts. Previously it lied and said `running`.
- A user `stop` is terminal and disarms `restart_every_minutes`. Only
  `completed` runs auto-restart via the restart interval.

## 2. New participant fields

`GET /runs`, `GET /runs/{id}` participants now include:

```json
{
  "status": "waiting",
  "resume_at": "2026-07-27T21:14:00Z",   // when the engine will auto-resume (null unless waiting)
  "progress": {                           // mode-specific, null until first written
    "kills_done": 42,                     // mob: kills across all cycles (counts against max_kills)
    "cycles_done": 3,                     // mob: completed passes (counts against run_count)
    "position": 5,                        // quest-list: next list position to run
    "quests_completed": 4,                // quest-list
    "quests_skipped": 1,                  // quest-list
    "relogin_attempts": 0                 // session-collision recovery bookkeeping
  }
}
```

Render `resume_at` for `waiting` participants ("resumes at 14:32"). The
polling model is unchanged (2–3 s on `GET /runs/{id}` / `GET /characters`);
a `waiting` run can be polled much more lazily until `resume_at`.

## 3. New run endpoints

| method | path | behavior |
|---|---|---|
| `POST` | `/api/v1/runs/{run}/pause` | Graceful pause. Allowed when run is `pending`/`running`/`waiting` (else 422). Live workers finish their current action and park (`pausing` → `paused`); waiting/pending participants pause instantly. |
| `POST` | `/api/v1/runs/{run}/resume` | Allowed only when run is `paused` (else 422). Re-dispatches every paused participant; each re-applies skill options (skill sync + cast-on-start + Circ gate) at pickup and **continues from persisted progress**. |
| `DELETE` | `/api/v1/runs/{run}` | Deletes a **finished** run and its participants (422 while live/parked — stop first). |

Pause → change per-character `cast_on_start` skill selection (`PUT
/characters/{id}/skills`, unchanged) → resume is the supported flow for
"add skills mid-run without losing progress".

`POST /runs/{run}/stop` is unchanged in shape but now also finalizes
`waiting`/`paused` participants immediately.

## 4. Mob (farming) run options

`POST /runs` with `mode: "mob"` accepts two new optional config fields:

- `run_count` (int ≥ 0, default 0): number of **passes** per character. One
  pass = attack the selected mobs until none are left alive or the rage
  floor is hit. 0/absent = unbounded (cycles while an interval or
  `require_circumspect` gives it a reason to; otherwise classic single pass).
- `attack_interval_seconds` (int 60–86400, optional): wait between passes
  (mob respawn time). Between passes the participant is `waiting` with
  `resume_at` set.

Interactions worth surfacing in the UI:

- `max_kills` is a total across all passes and pauses.
- Rage-out between passes waits for rage: the longer of the interval or 30
  minutes — or the Circumspect recharge when `require_circumspect` is on.
- The echoed `config` object now always contains `run_count` and
  `attack_interval_seconds` (old runs created before this change lack them).

## 5. Characters: live activity + stats freshness

- **`characters.status` is now live** (previously always `null` despite the
  docs): `idle | running | waiting | paused`, mirrored from the character's
  run participant in real time. Safe to render as the fleet grid's status
  column. Remove any client-side derivation of activity from run payloads.
- **Automatic stat refresh.** After a successful `POST /rgas/{id}/login`,
  `POST /rgas/{id}/session`, or `POST /rgas/{id}/sync-characters`, the API
  queues one background stats read per character — rage/exp/level and
  `last_stats_at` land within seconds of connecting. A scheduler also
  refreshes idle characters whose stats are older than ~15 minutes, so the
  grid never goes stale. In-run characters were already refreshed after
  every attack.
- **Manual refresh endpoints:**

| method | path | behavior |
|---|---|---|
| `POST` | `/api/v1/characters/{character}/refresh-stats` | Synchronous single-character refresh; returns the updated character (422 when the RGA has no session). |
| `POST` | `/api/v1/rgas/{rga}/refresh-stats` | Queues a fleet-wide refresh; returns 202 immediately (grid fills in via polling). 422 without a session. |

## 6. RGA management

- **`PUT/PATCH /api/v1/rgas/{rga}`** now exists: accepts `password` and/or
  `security_answer` (both optional, encrypted at rest). `username` is
  immutable and silently ignored if sent — a different account is a new RGA.
  The client can finally offer "edit credentials" instead of delete+recreate.
- The "second word" (`security_answer`) remains optional everywhere.

## 7. Run starts & stability behavior the client should know

- **One character, one run.** `POST /runs` returns 422 (`characters` key,
  message lists the names) when any selected character is still in an
  unfinished run (`pending`/`running`/`stopping`/`pausing`/`paused`/
  `waiting`). Surface this message; offer to stop the other run.
- **Session collisions self-heal.** When the game boots the API's session
  (e.g. the user logged into the game in a browser), running participants
  no longer all die. The API re-logs in once per RGA and parked participants
  resume within ~1 minute. After 3 failed recoveries in a cycle the
  participant fails for real. **Note:** the automatic re-login boots the
  user's browser session, same as the manual login endpoint — worth a UI
  hint ("running bots may re-claim the session").
- `rga.status` flips to `invalid` on collision and back to `active` after
  the automatic re-login — treat brief `invalid` blips during runs as
  self-healing, not as a call to action, unless they persist.

## 8. Client-side things to remove

- Any treatment of `failed` as a normal rage-out outcome.
- Any special handling for Circ-gated runs ending permanently (`stopped` +
  "Circumspect not active — run gated." never happens anymore; the
  equivalent state is `waiting` + "Waiting for Circumspect — resumes …").
- Any assumption that `characters.status` is null/dead.
- Any assumption that a scheduled run is immediately `running`.
- Any UI that blocks editing RGA credentials (update endpoint exists now).

## 9. Unchanged

- Auth (Sanctum bearer tokens), the polling model (no websockets yet),
  `GET /runs`/`GET /runs/{id}`/`GET /runs/{run}/battles` shapes (aside from
  the new participant fields), quest/quest-list/PvP config fields, skill
  endpoints, quest-list management, world/quest catalogs.
