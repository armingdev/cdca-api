---
paths:
  - phpstan.neon
---

# General

## PHPStan runs at level 6 with no baseline — keep it that way
`composer run analyse` must stay at zero errors; there is deliberately no baseline, so fix the cause rather than suppressing. CI runs it.

`parseModelCastsMethod: true` is load-bearing: models declare casts via `protected function casts(): array` with a `@return array<string, string>` docblock, and that docblock hides the constant array Larastan needs. Without the setting every cast attribute degrades to `string` and ~145 false errors appear.

Only `app`, `database` and `routes` are analysed. Tests are excluded on purpose — they lean on framework dynamism PHPStan cannot model (facade spies, pivot attributes, by-reference fake-world closures). Level 7 is reachable at roughly 26 further fixes, mostly `mixed` handling.
