---
paths:
  - 'config/**'
---

# Config

## PostgreSQL only, with keepalives and binding masking
The app uses PostgreSQL-only SQL (`make_interval`, `count(*) filter`, `ilike`), so `phpunit.xml` pins `DB_CONNECTION=pgsql` and CI runs a real Postgres — never substitute SQLite.

The `pgsql` connection sets `keepalives*` (run jobs hold a connection for up to 2h while waiting on the game, long enough for an idle socket to be dropped) and `mask_bindings_in_exception_messages => true` (RGA passwords and session cookies are written as query bindings and must not reach the logs). Both are covered by `tests/Feature/DatabaseSafetyTest.php`.

Never call `env()` outside `config/` — an arch test enforces it.
