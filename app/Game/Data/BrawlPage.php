<?php

namespace App\Game\Data;

use App\Game\Enums\BrawlType;
use Carbon\CarbonImmutable;

/**
 * A parsed `/closedpvp` page: the round's schedule plus its standings, which
 * double as the participant list — the target source for brawl modes.
 *
 * Before the window opens the standings list registrants with 0 wins, which
 * is also how we tell whether *we* are entered.
 */
final readonly class BrawlPage
{
    /**
     * @param  list<BrawlStanding>  $standings
     */
    public function __construct(
        public BrawlType $type,
        public array $standings,
        public ?int $roundId = null,
        public ?CarbonImmutable $startsAt = null,
        public ?int $participantCount = null,
        public ?string $startDateLabel = null,
        public ?string $endDateLabel = null,
        public bool $canEnter = false,
    ) {}

    /** Whether the given character id appears in the standings. */
    public function isEntered(int $playerId): bool
    {
        foreach ($this->standings as $standing) {
            if ($standing->playerId === $playerId) {
                return true;
            }
        }

        return false;
    }

    /** Standings minus ourselves — the attackable participants. */
    public function opponentsFor(int $playerId): array
    {
        return array_values(array_filter(
            $this->standings,
            fn (BrawlStanding $standing): bool => $standing->playerId !== $playerId,
        ));
    }
}
