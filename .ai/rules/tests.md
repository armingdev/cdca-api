---
paths:
  - 'tests/**'
---

# Tests

## Tests must never reach the live game servers, and shared helpers live in tests/Pest.php
`tests/Pest.php` calls `Http::preventStrayRequests()` in a global `beforeEach` for Feature tests: any request without a matching `Http::fake()` throws instead of hitting sigil/torax with a real session. When a new test drives engine code, fake every endpoint it touches (including indirect ones like `skills_info.php` behind the Circumspect gate).

The suite runs under `--parallel`, which shards by file. A helper function used by more than one test file MUST be defined in `tests/Pest.php` — defining it in a sibling test file works serially and fails in parallel with "Call to undefined function".
