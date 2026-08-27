---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## GameException renders as 422 centrally — never catch it in a controller
`bootstrap/app.php` maps any `App\Game\Exceptions\GameException` to a 422 JSON `{message}`. Let it bubble out of the controller; do not add `try { ... } catch (GameException $e) { return response()->json(...) }`.

Authorization is `Gate::authorize()` inside the controller method — Form Requests all `authorize(): true`. Every request-input endpoint takes a Form Request (there are zero `$request->validate()` calls in app/), and every paginated index caps `per_page` with `['sometimes','integer','min:1','max:100']`.
