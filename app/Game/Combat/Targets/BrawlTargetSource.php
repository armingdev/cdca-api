<?php

namespace App\Game\Combat\Targets;

use App\Game\Data\AttackTarget;
use App\Game\Data\BrawlPage;
use App\Game\Enums\BrawlType;
use App\Game\Http\GameClient;
use App\Game\Parsers\BrawlPageParser;
use App\Models\BrawlRound;
use App\Models\Character;
use App\Models\PlayerCharacter;

/**
 * Targets from a Brawl's standings table, which doubles as its participant
 * list. Excludes ourselves.
 *
 * Standings carry no attack hash, so targets need a search hop before the
 * attack — same as crew rosters.
 *
 * The in-window attack mechanics are NOT yet verified (the 2026-08-22
 * capture caught both events dormant), which is why the brawl runner refuses
 * to attack until a live capture confirms them. Reading the page, tracking
 * the schedule and entering are all verified and safe.
 */
class BrawlTargetSource implements PvpTargetSource
{
    private ?BrawlPage $page = null;

    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly BrawlPageParser $parser,
        private readonly BrawlType $type,
    ) {}

    public static function forType(Character $character, BrawlType $type): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(BrawlPageParser::class),
            $type,
        );
    }

    /** Load (and cache for this pass) the brawl page. */
    public function page(): BrawlPage
    {
        if ($this->page !== null) {
            return $this->page;
        }

        $page = $this->parser->parse($this->client->get($this->type->pageUrl())->body(), $this->type);

        if ($page->roundId !== null) {
            BrawlRound::updateOrCreate(
                [
                    'server_id' => $this->character->server_id,
                    'type' => $this->type->value,
                    'round_id' => $page->roundId,
                ],
                [
                    'starts_at' => $page->startsAt,
                    // The window is a fixed 12 hours from the start.
                    'ends_at' => $page->startsAt?->addHours(12),
                    'participant_count' => $page->participantCount,
                    'synced_at' => now(),
                ],
            );
        }

        return $this->page = $page;
    }

    /** Register this character for the round. */
    public function enter(): bool
    {
        $this->client->get($this->type->enterUrl());
        $this->page = null;

        return $this->page()->isEntered($this->character->suid);
    }

    public function isEntered(): bool
    {
        return $this->page()->isEntered($this->character->suid);
    }

    /**
     * @return list<AttackTarget>
     */
    public function targets(): array
    {
        $targets = [];

        foreach ($this->page()->opponentsFor($this->character->suid) as $standing) {
            $target = $standing->toAttackTarget();

            PlayerCharacter::remember($this->character->server_id, $target);

            $targets[] = $target;
        }

        return $targets;
    }

    public function label(): string
    {
        return strtolower($this->type->label());
    }
}
