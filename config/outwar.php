<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Game Hosts
    |--------------------------------------------------------------------------
    |
    | The RGA login lives on the apex host; each game server is a subdomain
    | selected by its server id (1 = sigil, 2 = torax). One RGA session works
    | across both servers.
    |
    | Must stay the apex: as of 2026-08-09 `www.outwar.com` answers a 301 to
    | `outwar.com`, and the login POST cannot follow it (a 301 drops the body).
    |
    */

    'login_host' => env('OUTWAR_LOGIN_HOST', 'https://outwar.com'),

    'servers' => [
        1 => ['name' => 'sigil', 'host' => 'https://sigil.outwar.com'],
        2 => ['name' => 'torax', 'host' => 'https://torax.outwar.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    |
    | Every game request goes through one throttled client per character. The
    | throttle sleeps a jittered interval between requests to the game server.
    | Timeout mirrors the reference tool's 12s default.
    |
    */

    'http' => [
        'timeout' => env('OUTWAR_HTTP_TIMEOUT', 12),
        'connect_timeout' => env('OUTWAR_HTTP_CONNECT_TIMEOUT', 5),
        'retry_times' => env('OUTWAR_HTTP_RETRY_TIMES', 3),
        'retry_sleep_ms' => env('OUTWAR_HTTP_RETRY_SLEEP_MS', 500),
        'throttle_min_ms' => env('OUTWAR_THROTTLE_MIN_MS', 300),
        'throttle_max_ms' => env('OUTWAR_THROTTLE_MAX_MS', 800),
        'user_agent' => env(
            'OUTWAR_USER_AGENT',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | World
    |--------------------------------------------------------------------------
    |
    | Room 1 is the world start; GET /world?room=1 teleports there from
    | anywhere (verified — works for room 1 only). The mapper uses it as a
    | reset hatch when trapped or desynced.
    |
    */

    'start_room_id' => 1,

    /*
    |--------------------------------------------------------------------------
    | Quests
    |--------------------------------------------------------------------------
    |
    | Some quest steps ask for an item the game only sells for real money, so
    | no amount of farming can satisfy them — the seeded source mobs for a
    | Quest Shard are end-game bosses that never drop one. Runs skip these
    | steps by default (skip_shard_quests); the names below are what "a
    | purchased item" means. Matching is case-insensitive and exact.
    |
    */

    'quest' => [
        'purchased_items' => ['Quest Shard'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Background Sync
    |--------------------------------------------------------------------------
    |
    | Signing in to CDCA queues a character-list read per connected RGA. A
    | roster barely changes, so repeat logins within this window reuse the
    | last one rather than sweeping both game servers again.
    |
    */

    'sync' => [
        'characters_debounce_minutes' => env('OUTWAR_CHARACTERS_SYNC_DEBOUNCE_MINUTES', 360),
    ],

    /*
    |--------------------------------------------------------------------------
    | Run Log
    |--------------------------------------------------------------------------
    |
    | The durable per-run decision journal (run_events). It answers "which
    | quests did it skip, and why" long after last_activity has moved on, but
    | it is append-only, so a retention window keeps the table bounded.
    |
    */

    'run_events' => [
        'retention_days' => env('OUTWAR_RUN_EVENTS_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Brawls
    |--------------------------------------------------------------------------
    |
    | The two fortnightly Brawl events (Monday 08:00-20:00 game time, every
    | 14 days). Reading the pages, tracking the schedule and entering a round
    | are all verified.
    |
    | The *in-window* attack mechanics are not: the 2026-08-22 capture caught
    | both events dormant, so we do not know whether attacks route through the
    | usual somethingelse.php, whether the 60-minute per-target cooldown is
    | suspended inside the window (it must be, for 10 hits per opponent in 12
    | hours), or where the hits-remaining counter is exposed. Until a live
    | capture confirms all three, brawl runs enter and observe but do not
    | attack. Set OUTWAR_BRAWL_ATTACKS_VERIFIED=true once confirmed.
    |
    */

    'brawl' => [
        'attacks_verified' => env('OUTWAR_BRAWL_ATTACKS_VERIFIED', false),
        'window_hours' => 12,
        'attacks_per_opponent' => 10,
    ],

];
