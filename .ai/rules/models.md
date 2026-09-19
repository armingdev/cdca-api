---
paths:
  - 'app/Models/**'
---

# Models

## Eloquent strict mode is on everywhere; production reports instead of throwing
`AppServiceProvider::boot()` calls `Model::shouldBeStrict()` unconditionally. Outside production a violation throws. In production the three `handle*ViolationUsing` callbacks `report()` the exception and keep the forgiving behaviour (the lazy load still loads, a missing attribute reads as `null`, an unfillable key is dropped) — throwing inside a multi-hour run costs more than the bug. Reports are rate limited per message in `bootstrap/app.php`. The callbacks are static on `Model`, so a test that boots the provider as production must null them in `afterEach` (see `tests/Feature/EloquentStrictModeTest.php`). Laravel only stamps the lazy-load guard on models hydrated from a multi-row result (`Builder::hydrate`, `count($items) > 1`), so a single route-model-bound model can still lazy load — the guard fires exactly where an N+1 would.

Conventions: casts via the `protected function casts(): array` method (never a `$casts` property), `protected $fillable` (no `$guarded`), typed relationship return types with `@return BelongsTo<Related, $this>` docblocks. `RunParticipant::transition()` writes the character's status by key (`Character::whereKey(...)->update(...)`) rather than through the relation, because it runs in engine loops.
