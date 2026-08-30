<?php

use App\Game\Parsers\CastConfirmationParser;

it('detects a successful cast and extracts the skill name', function () {
    $body = '<div>...</div>Status: You just cast Stealth<br><div>...</div>';

    $parser = new CastConfirmationParser;

    expect($parser->castSucceeded($body))->toBeTrue()
        ->and($parser->castSkillName($body))->toBe('Stealth');
});

it('reports failure when the confirmation marker is absent', function () {
    $parser = new CastConfirmationParser;

    expect($parser->castSucceeded('Your rage is too low to cast that skill.'))->toBeFalse()
        ->and($parser->castSkillName('nope'))->toBeNull();
});

it('only confirms the skill that was actually requested', function () {
    // The page can carry a status line from an earlier cast; reading that as
    // success stamps last_cast_at for a skill that never went off, which then
    // reads as "on cooldown" and blocks the retry.
    $body = 'Status: You just cast Fortify<br>';

    $parser = new CastConfirmationParser;

    expect($parser->castSucceededFor($body, 'Stealth'))->toBeFalse()
        ->and($parser->castSucceededFor($body, 'Fortify'))->toBeTrue()
        ->and($parser->castSucceededFor($body, ' fortify '))->toBeTrue()
        ->and($parser->castSucceededFor('Your rage is too low.', 'Fortify'))->toBeFalse();
});
