<?php

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/*
 * Two schedulers re-driving the same parked participants would double-dispatch
 * them, and an overlapping tick races the one still running. Every task —
 * including one added later outside the group — must carry both guards.
 */
it('runs every scheduled task on one server without overlapping', function () {
    $events = app(Schedule::class)->events();

    $unguarded = collect($events)
        ->reject(fn (Event $event) => $event->withoutOverlapping && $event->onOneServer)
        ->map(fn (Event $event) => $event->command);

    expect($events)->not->toBeEmpty()
        ->and($unguarded)->toBeEmpty();
});

it('warns when a scheduled task runs longer than 50 seconds', function () {
    Log::spy();

    event(new ScheduledTaskFinished(scheduledTask('outwar:runs-resume-due'), 70.0));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'Scheduled task ran longer than 50 seconds.'
            && str_contains($context['task'], 'outwar:runs-resume-due')
            && $context['runtime_seconds'] === 70.0);
});

it('stays quiet when a scheduled task finishes within 50 seconds', function () {
    Log::spy();

    event(new ScheduledTaskFinished(scheduledTask('outwar:runs-resume-due'), 10.0));

    Log::shouldNotHaveReceived('warning');
});

function scheduledTask(string $command): Event
{
    return collect(app(Schedule::class)->events())
        ->sole(fn (Event $event) => str_contains((string) $event->command, $command));
}
