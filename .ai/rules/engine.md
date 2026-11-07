---
paths:
  - 'app/Game/Engine/**'
---

# Engine

## Park vs stop: the engine's two "we cannot continue" verdicts
A run engine must distinguish *temporary* from *terminal*, because jobs map the two to Waiting vs Stopped and a misfiled verdict silently kills a multi-hour run.

Temporary (park, then resume): `TargetsDepleted` (spawn rooms reached, nothing alive — mobs are on their timer), `RageInsufficient` (the game's own per-mob price exceeds held rage; only the hourly rage tick fixes it), `RageExhausted` (the configured floor), `CircumspectExpired`.

Terminal: `Stuck` (no mapped rooms, none reachable, dead link), `Outmatched`, `RequiresPurchasedItem`, `TargetReached`, `Completed`.

Two traps this encodes:
- Never key "targets are depleted" on the room blob's `isDead` flag alone. Verified 2026-11-07: a killed ordinary mob (`type: 2`) is dropped from `roomDetailsNew` entirely, so there is no corpse to see; only spawned mobs (`type: 3`) and raid/gods (`type: 1`) stay listed with `isDead: true`. `MobRunner` therefore counts *mapped spawn rooms it actually stood in*. See docs/game-api/parsing.md.
- An absent *spawnable* target is not "on its timer": it has to be drawn out by killing its room-mates (`MobRunner::huntSpawn`). Those room-mate kills are `triggerKills`, never `wins` — counting them as progress would reset the barren-wait counter and let a hunt that never spawns anything run forever.
- Never let a refused attack loop. A refusal is a 200 with an empty body, costs no rage and leaves the mob standing, so the loop re-attacks forever. Check `MobSighting::$rageCost` against current rage before attacking, and cap consecutive failures per mob.

Rage waits go to `GameClock::nextRageTickAt()` (the game clock is UTC-5, whole-hour offset), never a fixed nap. Every park is bounded by a counter in `progress` that resets when a cycle makes progress.
