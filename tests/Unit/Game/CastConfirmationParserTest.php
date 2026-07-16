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
