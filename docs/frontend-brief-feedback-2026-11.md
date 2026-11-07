# Frontend brief — tester feedback batch (API, 2026-11)

Audience: a Claude session working in `/Users/armingerina/Code/cdca/cdca-client`
(Angular 22, standalone components, signals + `@angular/forms/signals`, Vitest).

A tester sent a new list of wishes. The API is built phase by phase and this
brief grows with it. **Phase 1 (accounts and sessions) is done**; the run
features (multiple quest lists, run priority, potions, spawned mobs) follow in
later sections once their API lands. Every API change so far is additive:
nothing in the client breaks if none of this is done yet.

Full endpoint shapes: `cdca-api/docs/api-reference.md` (§2 auth, §4 RGAs →
Sessions / Challenge tokens, §5 characters, §5b crew groups).

Work order suggestion: §1.6 (favicon, five minutes) and §1.5 (fleet level
range) first, then the password screens, then sessions, tokens and crew groups.

---

## 1. Accounts and sessions (Phase 1 — API done)

### 1.1 Forgot password / change password

New endpoints (all JSON):

- `POST /forgot-password { email }` → always `200` with a generic message
  (the API never says whether the email exists — show the message as-is).
- `POST /reset-password { token, email, password, password_confirmation }` →
  `200`; a bad/expired link → `422 errors.email` "This password reset link is
  invalid or has expired.". **All tokens are revoked**: send the user to
  `/login` with a "password changed, sign in" notice.
- `PUT /user/password { current_password, password, password_confirmation }`
  (authed) → `200`; this device stays signed in, every other device is signed
  out. `422` on `current_password` (wrong) or `password` (too short, not
  confirmed, or the same as the current one).

The reset email (Postmark, from `no-reply@cdcaow.com`) links to
**`{client}/reset-password?token=…&email=…`**, so the client needs that route.

Client:

- `features/auth/login-page.html` — add a "Forgot password?" link under the
  password field → new `forgot-password` route (guest-only, `guestGuard` like
  `login`/`register` in `app.routes.ts:17-27`). One email field; on submit
  show the API message and keep the form disabled for ~60 s (the API throttles
  a repeat to one per minute per user anyway).
- New `reset-password` route (guest-only): read `token` and `email` from the
  query string, show new password + confirm, `POST /reset-password`, then
  navigate to `/login` with a success banner. Show `errors.email[0]` on 422
  with a link back to "Forgot password?".
- Signed-in "Change password" form — there is no settings page today; the
  natural home is a small card on `features/accounts/accounts-page` or the
  user menu in `layout/shell.html`. Fields: current, new, confirm. Map the
  422 field errors to the inputs.
- `core/api` has no auth API file for this — add the three calls next to the
  existing login/register calls used by `core/auth/auth-store.ts`.

### 1.2 Copy all active sessions with one click

`GET /rgas/sessions[?verify=1]` → `{ data: [{ rga_id, username, rg_sess_id,
token, cuserid2, status, last_login_at, characters_count, alive }] }`, ordered
by username, only RGAs with a live session.

- Without `verify` it answers from the database instantly (`alive: null`).
- With `verify=1` the API asks the game about each session (about 1 s per
  RGA): `alive: false` means the game rejected it and the RGA is now
  `invalid`; `alive: null` means the game could not be reached.

Client (`features/accounts/accounts-page`):

- A "Copy all sessions" button in the page header. Call with `verify=1`,
  then copy one line per live session to the clipboard. Suggested format
  `username: rg_sess_id` (the tester pastes into other tools — confirm the
  format with the tester; plain ids one per line is the alternative).
- Show a toast: "Copied N sessions" plus "M sessions are dead — log in
  again?" listing the `alive: false` usernames. Re-logging is the existing
  `POST /rgas/{id}/login` (`rgas-api.ts:43`).
- `GET /rgas/{id}/session` (the existing per-RGA reveal) now also returns
  `rga_id` and `username`.

### 1.3 Crew leader mode (crew groups)

A cdca crew: a leader and members who joined with an invite code. Each
member picks which of **their own** RGAs the leader may read; the leader
copies those sessions in one click. Every leader read is logged and visible
to all members. Full table in api-reference §5b.

Client — new feature folder, e.g. `features/crew-groups`, plus a nav entry in
`layout/shell.html`:

- **List** (`GET /crew-groups`): name, leader, members count, a "Leader"
  badge on `is_leader`. Buttons: "Create crew" (`POST { name }`) and "Join
  with code" (`POST /crew-groups/join { invite_code }`; show
  `errors.invite_code[0]` on 422).
- **Detail** (`GET /crew-groups/{id}`):
  - Leader view: invite code with copy and "New code" buttons (`POST …/invite-code`),
    rename, disband (confirm dialog), the members list with each member's
    `shared_rgas_count` and a remove button (`DELETE …/members/{userId}`),
    and **"Copy crew sessions"** → `GET …/sessions?verify=1`, copying the
    rows with `has_session: true` in the same format as §1.2. The rows also
    list each RGA's characters (name, level, server) — show them in a table
    under the button.
  - Member view: "My shared accounts" — a checklist of the user's own RGAs
    (from `GET /rgas`) pre-ticked from `shared_rgas`; saving sends the full
    set to `PUT …/shared-rgas { rga_ids }` (`[]` = share nothing). Plus a
    "Leave crew" button (`DELETE …/members/{myUserId}`).
  - Both: "Session reads" — the audit trail (`GET …/session-reads`,
    paginated): who read, when, how many accounts, verified or not.
- Explain the trust model in the UI copy: sharing gives the leader full
  control of that game account until the member un-shares it or logs the
  account in again elsewhere.

### 1.4 Auto-claim challenge tokens

Challenge tokens: 3 per day per RGA, at most 30 held. The API now claims
them automatically (scheduler, every 30 min) for every RGA with
`auto_claim_tokens` on — **on by default**.

- `RgaResource` gains `auto_claim_tokens`, `challenge_tokens`,
  `challenge_tokens_purchased`, `challenge_claim_at` (null until first read).
- `PUT /rgas/{id} { auto_claim_tokens: bool }` toggles it.
- `GET /rgas/{id}/challenge-tokens` reads live; `POST …/challenge-tokens/claim`
  claims now (`200` + `message`; `422` + the game's reason, e.g. "You have
  reached your max of 30 challenge tokens!").

Client (`features/accounts/accounts-page`): per RGA row, show
"Tokens 27/30 (+5 bought)" and the next claim time, an "Auto-claim" toggle,
and a "Claim now" action that shows the returned `message`.

### 1.5 Fleet: filter by level range

`GET /characters` accepts `min_level`/`max_level`, but the fleet grid filters
client-side, so this is a client change.

Client (`features/fleet`):

- Copy the quest finder's two level inputs and their hint
  (`features/quests/quest-finder-page.html:37-65`; the `minLevel`/`maxLevel`
  signals, `levelRangeInvalid`, `onMinLevelChange`/`onMaxLevelChange` in
  `quest-finder-page.ts:39-40,55,120-125`) into `fleet-page.html` next to the
  search field.
- Add `(min === null || c.level >= min) && (max === null || c.level <= max)`
  to the `filtered` predicate in `fleet-page.ts:58-88`, ignoring the range
  while it is invalid.
- The existing "select all filtered" checkbox (`toggleAllFiltered()`,
  `fleet-page.ts:132`) then selects exactly the characters in the range, and
  they carry into "New run" through `FleetSelection`.
- Spec: a 40–60 range shows only levels 40–60, and select-all selects
  exactly them.

**API bug fixed along the way:** `GET /quests?max_level=30` without a
`min_level` used to be a `422` ("must be greater than or equal to min
level"). It now works. If the quest finder had a workaround for that, it can go.

### 1.6 Favicon

`public/favicon.ico` is still the Angular CLI default (15,086 bytes, from
2026-07-22). `public/favicon.svg` already draws the header mark (the
`rage-500` clipped square from `shared/brand.ts`). Browsers that pick the
`.ico` (Safari, and Chrome when `sizes` matches) show the Angular logo.

- Regenerate `favicon.ico` (16/32/48) from `favicon.svg`.
- Add `<link rel="apple-touch-icon" href="apple-touch-icon.png">` (180×180,
  the mark on `#131110`) to `src/index.html`.
- Keep the SVG `<link>` first in `index.html:10-11`.

---

## 4. Type changes checklist (`core/models`)

- `rga.ts`
  - `Rga` += `auto_claim_tokens: boolean`, `challenge_tokens: number | null`,
    `challenge_tokens_purchased: number | null`, `challenge_claim_at: string | null`.
  - `UpdateRgaRequest` += `auto_claim_tokens?: boolean`.
  - `RgaSession` += `rga_id: number`, `username: string`.
  - New `RgaSessionRow extends RgaSession { characters_count: number; alive: boolean | null }`.
  - New `ChallengeTokens { rga_id; auto_claim_tokens; tokens: number | null; max_tokens: 30; purchased_tokens: number | null; next_claim_at; claimed_at; synced_at }`.
- `user.ts` — new request types `ForgotPasswordRequest`, `ResetPasswordRequest`,
  `UpdatePasswordRequest`.
- New `crew-group.ts` — `CrewGroup`, `CrewGroupMember`, `CrewGroupSharedRga`,
  `CrewGroupSession` (with `characters: { suid, server_id, name, level }[]`),
  `CrewGroupSessionRead`.
- `characters-api.ts` — optional `min_level`/`max_level` query params.
