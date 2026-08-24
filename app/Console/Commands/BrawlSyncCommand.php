<?php

namespace App\Console\Commands;

use App\Game\Combat\Targets\BrawlTargetSource;
use App\Game\Enums\BrawlType;
use App\Models\BrawlRound;
use App\Models\Character;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('outwar:brawl-sync {--character= : Character id to read the pages as}')]
#[Description('Refresh the fortnightly Brawl schedules from /closedpvp')]
class BrawlSyncCommand extends Command
{
    /**
     * Brawl windows are the game's to define — a fortnightly Monday cadence
     * we read rather than compute, so a shifted or cancelled event does not
     * leave runs firing at nothing.
     *
     * One character per server is enough: the schedule is server-wide.
     */
    public function handle(): int
    {
        $characters = $this->charactersToUse();

        if ($characters->isEmpty()) {
            $this->error('No character with a live session to read the brawl pages with.');

            return self::FAILURE;
        }

        foreach ($characters as $character) {
            foreach (BrawlType::cases() as $type) {
                $page = BrawlTargetSource::forType($character, $type)->page();

                $this->line(sprintf(
                    '%s (server %d): round %s, starts %s, %d participant(s).',
                    $type->label(),
                    $character->server_id,
                    $page->roundId ?? '?',
                    $page->startsAt?->toDateTimeString() ?? 'unknown',
                    $page->participantCount ?? 0,
                ));
            }
        }

        $upcoming = BrawlRound::query()->upcoming()->first();

        if ($upcoming !== null) {
            $this->info(sprintf(
                'Next window: %s at %s.',
                $upcoming->type->label(),
                $upcoming->starts_at->toDateTimeString(),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Character>
     */
    private function charactersToUse()
    {
        if ($this->option('character') !== null) {
            return Character::query()->where('id', (int) $this->option('character'))->get();
        }

        // One live-session character per server.
        return Character::query()
            ->with('rga')
            ->get()
            ->filter(fn (Character $character): bool => $character->rga?->hasSession() ?? false)
            ->unique('server_id')
            ->values();
    }
}
