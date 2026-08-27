---
paths:
  - 'app/Models/**'
---

# Models

## Eloquent strict mode is on outside production
`AppServiceProvider::boot()` calls `Model::shouldBeStrict(! $this->app->isProduction())`. Laravel only stamps the lazy-load guard on models hydrated from a multi-row result (`Builder::hydrate`, `count($items) > 1`), so a single route-model-bound model can still lazy load — the guard fires exactly where an N+1 would.

Conventions: casts via the `protected function casts(): array` method (never a `$casts` property), `protected $fillable` (no `$guarded`), typed relationship return types with `@return BelongsTo<Related, $this>` docblocks. `RunParticipant::transition()` writes the character's status by key (`Character::whereKey(...)->update(...)`) rather than through the relation, because it runs in engine loops.
