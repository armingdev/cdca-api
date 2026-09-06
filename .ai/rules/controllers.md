---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## GameException renders as 422 centrally — never catch it in a controller
`bootstrap/app.php` maps any `App\Game\Exceptions\GameException` to a 422 JSON `{message}`. Let it bubble out of the controller; do not add `try { ... } catch (GameException $e) { return response()->json(...) }`.

Authorization is `Gate::authorize()` inside the controller method — Form Requests all `authorize(): true`. Every request-input endpoint takes a Form Request (there are zero `$request->validate()` calls in app/), and every paginated index caps `per_page` with `['sometimes','integer','min:1','max:100']`.

## A resource collection nested in response()->json() is NOT data-wrapped
`'skills' => CharacterSkillResource::collection($rows)` inside a `response()->json([...])` array serializes through `jsonSerialize()`, which skips the `data` envelope. Only a resource returned as the whole response gets wrapped.

This silently shipped a broken client: the Angular skills page read `response.skills.data` (undefined) and blanked its own list on every sync, while its unit-test fixture invented the envelope so nothing caught it.

Convention here: nested lists stay plain arrays (`POST /characters/{id}/teleports/sync` does the same and its client type says so). Whichever side you pick, assert the shape in a feature test — `assertJsonCount(n, 'skills')` vs `'skills.data'` is the check that catches it.
