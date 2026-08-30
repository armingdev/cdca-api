<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('outwar:runs-restart-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('outwar:runs-resume-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('outwar:stats-refresh-stale')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Brawl windows are fortnightly, so an hourly read is ample to keep the
// schedule current without hammering the pages.
Schedule::command('outwar:brawl-sync')->hourly()->withoutOverlapping()->onOneServer();

// The run log is append-only and a 200-quest fleet run writes thousands of
// rows; without this the table only ever grows.
Schedule::command('outwar:run-events-prune')->dailyAt('04:10')->withoutOverlapping()->onOneServer();

// Horizon's metrics dashboard stays blank unless snapshots are taken. One
// server only — duplicate snapshots skew the throughput/runtime metrics.
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
