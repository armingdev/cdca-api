<?php

use App\Game\Enums\BrawlType;
use App\Game\GameClock;
use App\Game\Parsers\BrawlPageParser;

it('parses the brawl schedule from the countdown epoch, not the rendered label', function () {
    $page = new BrawlPageParser()->parse(gameFixture('closedpvp_brawl_prestart.html'), BrawlType::Pvp);

    // The label reads "August 31st 8:00AM" — no year, no timezone. The epoch
    // behind the FlipClock is unambiguous.
    expect($page->startsAt?->toIso8601String())->toBe('2026-08-31T13:00:00+00:00')
        ->and($page->startDateLabel)->toBe('August 31st 8:00AM')
        ->and($page->endDateLabel)->toBe('August 31st 8:00PM');
});

it('renders that start instant as 8am on the game clock', function () {
    $page = new BrawlPageParser()->parse(gameFixture('closedpvp_brawl_prestart.html'), BrawlType::Pvp);

    expect($page->startsAt?->setTimezone(GameClock::OFFSET)->format('H:i'))->toBe('08:00');
});

it('reads the standings table as the participant list', function () {
    $page = new BrawlPageParser()->parse(gameFixture('closedpvp_brawl_prestart.html'), BrawlType::Pvp);

    expect($page->participantCount)->toBe(3)
        ->and($page->standings)->toHaveCount(3);

    expect($page->standings[0]->name)->toBe('Oddy')
        ->and($page->standings[0]->playerId)->toBe(113903)
        ->and($page->standings[0]->rank)->toBe(1)
        ->and($page->standings[0]->wins)->toBe(0);
});

it('does not mistake the reward or champion tables for standings', function () {
    $page = new BrawlPageParser()->parse(gameFixture('closedpvp_brawl_prestart.html'), BrawlType::Pvp);

    $names = array_map(fn ($standing) => $standing->name, $page->standings);

    expect($names)->toBe(['Oddy', 'OneEightSe7en', 'XxTheDarkLordxX'])
        ->and($names)->not->toContain('PaasHaaS');
});

it('tells a character whether it is entered, and who it may attack', function () {
    $page = new BrawlPageParser()->parse(gameFixture('closedpvp_brawl_prestart.html'), BrawlType::Pvp);

    expect($page->isEntered(113903))->toBeTrue()
        ->and($page->isEntered(999999))->toBeFalse()
        ->and($page->opponentsFor(113903))->toHaveCount(2)
        ->and($page->canEnter)->toBeTrue();
});

it('derives the current round id from the countdown wrapper', function () {
    $page = new BrawlPageParser()->parse(gameFixture('closedpvp_brawl_prestart.html'), BrawlType::Pvp);

    expect($page->roundId)->toBe(179);
});

it('exposes the two brawl variants distinctly', function () {
    expect(BrawlType::Pvp->requiredLevel())->toBe(85)
        ->and(BrawlType::Faction->requiredLevel())->toBe(95)
        ->and(BrawlType::Pvp->pageUrl())->toBe('closedpvp')
        ->and(BrawlType::Faction->pageUrl())->toBe('closedpvp?type=1')
        ->and(BrawlType::Faction->enterUrl())->toBe('closedpvp?enter=1&type=1');
});
