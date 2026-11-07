<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Every task runs on one server and never overlaps itself: two schedulers
// re-driving the same parked participants would double-dispatch them.
Schedule::withoutOverlapping()->onOneServer()->group(function (): void {
    Schedule::command('outwar:runs-restart-due')->everyMinute();
    Schedule::command('outwar:runs-resume-due')->everyMinute();

    // A worker killed outright (OOM, hard restart) cannot report its own death;
    // without this its participants sit at "Running" until the queue redelivers
    // the job two hours later.
    Schedule::command('outwar:runs-recover-stalled')->everyMinute();

    Schedule::command('outwar:stats-refresh-stale')->everyFiveMinutes();

    // Brawl windows are fortnightly, so an hourly read is ample to keep the
    // schedule current without hammering the pages.
    Schedule::command('outwar:brawl-sync')->hourly();

    // The run log is append-only and a 200-quest fleet run writes thousands of
    // rows; without this the table only ever grows.
    Schedule::command('outwar:run-events-prune')->dailyAt('04:10');

    // Daily challenge tokens (3/day per RGA) for every RGA with auto-claim on.
    // Each RGA stores when it is next due, so most runs queue nothing.
    Schedule::command('outwar:challenge-tokens-claim')->everyThirtyMinutes();

    // Expired password-reset tokens otherwise accumulate in password_reset_tokens.
    Schedule::command('auth:clear-resets')->dailyAt('04:20');

    // Horizon's metrics dashboard stays blank unless snapshots are taken. One
    // server only — duplicate snapshots skew the throughput/runtime metrics.
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
});

// An every-minute task that runs past a minute silently skips its next tick
// (withoutOverlapping), and a skipped runs-resume-due tick leaves parked runs
// at "Waiting" with no error anywhere. Say so while there is still headroom.
Schedule::whenTaskRunsLongerThan(50, function (Event $event, float $runtime): void {
    Log::warning('Scheduled task ran longer than 50 seconds.', [
        'task' => $event->command ?? $event->description,
        'runtime_seconds' => round($runtime, 1),
    ]);
});
