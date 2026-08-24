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

it('detects the security prompt gate from the landing url', function () {
    $refusal = new AttackRefusalParser()->parse('<html></html>', 'https://sigil.outwar.com/security_prompt');

    expect($refusal->reason)->toBe(AttackRefusalReason::SecurityPrompt);
});

it('reports an unrecognised refusal rather than guessing at a reason', function () {
    $refusal = new AttackRefusalParser()->parse('<p>Something new happened.</p>');

    expect($refusal->reason)->toBe(AttackRefusalReason::Unknown)
        ->and($refusal->retryInMinutes())->toBeNull();
});
