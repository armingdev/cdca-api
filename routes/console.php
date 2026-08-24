<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outwar:runs-restart-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('outwar:runs-resume-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('outwar:stats-refresh-stale')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Brawl windows are fortnightly, so an hourly read is ample to keep the
// schedule current without hammering the pages.
Schedule::command('outwar:brawl-sync')->hourly()->withoutOverlapping()->onOneServer();

// Horizon's metrics dashboard stays blank unless snapshots are taken.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
