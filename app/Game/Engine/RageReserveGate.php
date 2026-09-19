<?php

namespace App\Game\Engine;

use App\Game\Data\RageReserveWindow;
use App\Game\Enums\RageReserveEvent;
use App\Game\Enums\RunMode;
use App\Models\BrawlRound;
use App\Models\Character;
use App\Models\Run;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps a run from burning the rage its user wants for an event: some hours
 * before a chosen event starts the run parks, and it picks up again once the
 * event is over.
 *
 * A park, not a stop — nothing is wrong with the run, and the window ends on
 * its own. It needs no retry counter either: a participant resumes at the
 * window's end, where the same window can no longer match, and the next
 * round of a fortnightly event is two weeks off.
 *
 * Event times are read from what outwar:brawl-sync stored, never computed, so
 * a shifted or cancelled round cannot park a run for nothing.
 */
class RageReserveGate
{
    /**
     * The next window this run has to respect for this character's server —
     * already open, or still ahead — or null when the run reserves nothing.
     */
    public function nextWindowFor(Run $run, Character $character): ?RageReserveWindow
    {
        // A brawl run exists to spend rage on the event, not to save it.
        if (in_array($run->mode, [RunMode::PvpBrawl, RunMode::PvpFactionBrawl], true)) {
            return null;
        }

        $events = array_filter(array_map(
            RageReserveEvent::tryFrom(...),
            $run->reserve_rage_for ?? [],
        ));

        if ($events === []) {
            return null;
        }

        $windows = [];

        foreach ($events as $event) {
            // The earliest round that is not over yet. A round synced without
            // an end time is taken to last the usual window.
            $round = BrawlRound::query()
                ->where('server_id', $character->server_id)
                ->where('type', $event->brawlType())
                ->where(fn (Builder $query) => $query
                    ->where('ends_at', '>', now())
                    ->orWhere(fn (Builder $query) => $query
                        ->whereNull('ends_at')
                        ->where('starts_at', '>', now()->subHours($this->windowHours()))))
                ->orderBy('starts_at')
                ->first();

            if ($round !== null) {
                $windows[] = new RageReserveWindow(
                    event: $event,
                    reserveFrom: $round->starts_at->copy()->subHours($run->reserve_rage_hours),
                    eventStartsAt: $round->starts_at,
                    resumeAt: $this->endOf($round),
                );
            }
        }

        usort($windows, fn (RageReserveWindow $a, RageReserveWindow $b): int => $a->reserveFrom <=> $b->reserveFrom);

        return $windows[0] ?? null;
    }

    private function endOf(BrawlRound $round): CarbonInterface
    {
        return $round->ends_at ?? $round->starts_at->copy()->addHours($this->windowHours());
    }

    private function windowHours(): int
    {
        return (int) config('outwar.brawl.window_hours');
    }
}
