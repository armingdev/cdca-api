<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Game Hosts
    |--------------------------------------------------------------------------
    |
    | The RGA login lives on the www host; each game server is a subdomain
    | selected by its server id (1 = sigil, 2 = torax). One RGA session works
    | across both servers.
    |
    */

    'login_host' => env('OUTWAR_LOGIN_HOST', 'https://www.outwar.com'),

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

];
