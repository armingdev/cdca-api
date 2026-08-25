<?php

use App\Game\Enums\AttackRefusalReason;
use App\Game\Parsers\AttackRefusalParser;

it('classifies the 60-minute cooldown refusal and reads the elapsed minutes', function () {
    $refusal = new AttackRefusalParser()->parse(gameFixture('pvp_attack_cooldown_refusal.html'));

    expect($refusal->reason)->toBe(AttackRefusalReason::Cooldown)
        ->and($refusal->minutesSinceLastAttack)->toBe(3)
        ->and($refusal->message)->toContain('once every 60 minutes');
});

it('turns the elapsed minutes into a precise retry rather than a blind 60-minute wait', function () {
    $refusal = new AttackRefusalParser()->parse(gameFixture('pvp_attack_cooldown_refusal.html'));

    expect($refusal->retryInMinutes())->toBe(57);
});

it('never schedules a retry in the past when the game reports a stale elapsed time', function () {
    $html = '<p>You can only attack someone once every 60 minutes, and you attacked this person 90 minutes ago.</p>';

    expect(new AttackRefusalParser()->parse($html)->retryInMinutes())->toBe(1);
});

it('reads the refusal off a whole page, ignoring the nav and modals', function () {
    // Regression: the engine always sees the full document, but the original
    // fixture was only the content region — so the parser was never exercised
    // against the chrome and, in production, built its message out of the main
    // menu instead. That message then blew the varchar(255) fail_reason column
    // and killed the queued job.
    $refusal = new AttackRefusalParser()->parse(gameFixture('pvp_attack_refusal_full_page.html'));

    expect($refusal->reason)->toBe(AttackRefusalReason::Cooldown)
        ->and($refusal->minutesSinceLastAttack)->toBe(3)
        ->and($refusal->retryInMinutes())->toBe(57);

    expect($refusal->message)
        ->toContain('once every 60 minutes')
        ->not->toContain('MY RGA')
        ->not->toContain('ATTACK / SEARCH')
        ->not->toContain('cost you');
});

it('never returns a message that would overflow the fail_reason column', function (string $fixture) {
    $refusal = new AttackRefusalParser()->parse(gameFixture($fixture));

    expect(strlen($refusal->message))->toBeLessThanOrEqual(255);
})->with([
    'content region' => 'pvp_attack_cooldown_refusal.html',
    'full page' => 'pvp_attack_refusal_full_page.html',
    'an unclassifiable full page' => 'crew_hitlist.html',
]);

it('clips an unrecognised refusal instead of storing a page of text', function () {
    $page = gameFixture('crew_hitlist.html');
    $refusal = new AttackRefusalParser()->parse($page);

    expect($refusal->reason)->toBe(AttackRefusalReason::Unknown)
        // 180 chars plus Str::limit's ellipsis — orders of magnitude below the
        // ~12KB of text this page carries.
        ->and(strlen($refusal->message))->toBeLessThan(200)
        ->and(strlen($page))->toBeGreaterThan(10_000);
});

it('classifies the refusals a crew hitlist actually produces', function (
    string $fixture,
    AttackRefusalReason $reason,
    string $contains,
) {
    // All three phrasings came out of a live crew-hitlist run's logs on
    // 2026-08-25 — a crew hitlist is mostly allies, so these are the common
    // case rather than edge cases.
    $refusal = new AttackRefusalParser()->parse(gameFixture($fixture));

    expect($refusal->reason)->toBe($reason)
        ->and($refusal->message)->toContain($contains);
})->with([
    'personal ally' => ['pvp_attack_refusal_ally.html', AttackRefusalReason::Allied, 'is your ally'],
    'allied crew' => ['pvp_attack_refusal_allied_crew.html', AttackRefusalReason::Allied, 'Allied crew'],
    'pvp immunity' => ['pvp_attack_refusal_pvp_immunity.html', AttackRefusalReason::PvpImmunity, 'PVP Immunity'],
]);

it('keeps the page footer out of the refusal message', function (string $fixture) {
    // The footer lives inside #content, so scoping to #content was not enough:
    // every unrecognised refusal used to end with the policy links.
    $refusal = new AttackRefusalParser()->parse(gameFixture($fixture));

    expect($refusal->message)
        ->not->toContain('Privacy Policy')
        ->not->toContain('Terms of Service')
        ->not->toContain('Contact Info');
})->with([
    'pvp_attack_refusal_ally.html',
    'pvp_attack_refusal_allied_crew.html',
    'pvp_attack_refusal_pvp_immunity.html',
    'pvp_attack_refusal_full_page.html',
]);

it('stops retrying a structurally unattackable target for far longer than a cooldown', function () {
    // An ally never becomes attackable by waiting an hour; on a 404-target
    // crew hitlist that is a wasted request per ally per pass, forever.
    expect(AttackRefusalReason::Allied->blockMinutes())->toBe(10080)
        ->and(AttackRefusalReason::Cooldown->blockMinutes())->toBe(60)
        ->and(AttackRefusalReason::PvpImmunity->blockMinutes())->toBe(60)
        ->and(AttackRefusalReason::Unknown->blockMinutes())->toBeNull();
});

it('detects the security prompt gate from the landing url', function () {
    $refusal = new AttackRefusalParser()->parse('<html></html>', 'https://sigil.outwar.com/security_prompt');

    expect($refusal->reason)->toBe(AttackRefusalReason::SecurityPrompt);
});

it('reports an unrecognised refusal rather than guessing at a reason', function () {
    $refusal = new AttackRefusalParser()->parse('<p>Something new happened.</p>');

    expect($refusal->reason)->toBe(AttackRefusalReason::Unknown)
        ->and($refusal->retryInMinutes())->toBeNull();
});
