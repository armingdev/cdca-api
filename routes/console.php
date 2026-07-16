<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outwar:runs-restart-due')->everyMinute();

// Horizon's metrics dashboard stays blank unless snapshots are taken.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
