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
- Never key "targets are depleted" on the room blob's `isDead` flag alone. It is unverified for the killed case and production shows cleared rooms omitting the entry entirely; `MobRunner` counts *mapped spawn rooms it actually stood in* instead. See the caveat in docs/game-api/parsing.md.
- Never let a refused attack loop. A refusal is a 200 with an empty body, costs no rage and leaves the mob standing, so the loop re-attacks forever. Check `MobSighting::$rageCost` against current rage before attacking, and cap consecutive failures per mob.

Rage waits go to `GameClock::nextRageTickAt()` (the game clock is UTC-5, whole-hour offset), never a fixed nap. Every park is bounded by a counter in `progress` that resets when a cycle makes progress.
