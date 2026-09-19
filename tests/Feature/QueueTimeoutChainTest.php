<?php

use App\Jobs\RefreshCharacterStatsJob;
use App\Jobs\RunMobJob;
use App\Jobs\SyncRgaCharactersJob;

/*
 * A reservation that expires while its job is still running gets the job
 * redelivered, and with a single try that surfaces as "has been attempted too
 * many times". Every link must stay ordered: job < supervisor < retry_after.
 */
it('keeps every job timeout below its supervisor timeout and its connection retry_after', function (
    string $job,
    string $connection,
    string $supervisor,
) {
    $jobTimeout = (new ReflectionClass($job))->getProperty('timeout')->getDefaultValue();
    $supervisorTimeout = config("horizon.defaults.{$supervisor}.timeout");
    $retryAfter = config("queue.connections.{$connection}.retry_after");

    expect($jobTimeout)->toBeLessThanOrEqual($supervisorTimeout)
        ->and($supervisorTimeout)->toBeLessThan($retryAfter);
})->with([
    'stats refresh' => [RefreshCharacterStatsJob::class, 'redis', 'supervisor-1'],
    'roster sync' => [SyncRgaCharactersJob::class, 'redis', 'supervisor-1'],
    'run jobs' => [RunMobJob::class, 'redis-runs', 'supervisor-runs'],
]);

it('runs each supervisor on the connection its timeout chain was sized for', function () {
    expect(config('horizon.defaults.supervisor-1.connection'))->toBe('redis')
        ->and(config('horizon.defaults.supervisor-runs.connection'))->toBe('redis-runs');
});
