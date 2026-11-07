<?php

use App\Game\Exceptions\ParseException;
use App\Game\Parsers\ChallengeTokenParser;

it('reads the captured status: string counts, negative timer = claim open', function () {
    $status = new ChallengeTokenParser()->parseStatus(gameFixture('challenge/challenge_status.json'));

    expect($status->tokens)->toBe(30)
        ->and($status->purchasedTokens)->toBe(5)
        ->and($status->secondsUntilClaim)->toBe(-9581808)
        ->and($status->claimOpen())->toBeTrue()
        ->and($status->atCap())->toBeTrue()
        ->and($status->claimable())->toBeFalse();
});

it('is claimable only with the timer up and room below the cap', function (string $body, bool $claimable) {
    expect(new ChallengeTokenParser()->parseStatus($body)->claimable())->toBe($claimable);
})->with([
    'timer up, room left' => ['{"result":true,"tokens":"27","p_tokens":"0","timeleft":-5}', true],
    'timer exactly up' => ['{"result":true,"tokens":"0","p_tokens":"0","timeleft":0}', true],
    'timer still running' => ['{"result":true,"tokens":"3","p_tokens":"0","timeleft":3600}', false],
    'at the cap' => ['{"result":true,"tokens":"30","p_tokens":"0","timeleft":-5}', false],
]);

it('reads the captured refusal at the cap', function () {
    $result = new ChallengeTokenParser()->parseClaim(gameFixture('challenge/challenge_claim_refused_max.json'));

    expect($result->claimed)->toBeFalse()
        ->and($result->message)->toBe('You have reached your max of 30 challenge tokens!');
});

it('reads a successful claim', function () {
    $result = new ChallengeTokenParser()->parseClaim('{"result":true,"message":"You have claimed 3 challenge tokens!"}');

    expect($result->claimed)->toBeTrue()
        ->and($result->message)->toBe('You have claimed 3 challenge tokens!');
});

it('rejects a body that is not the challenge JSON', function (string $method) {
    new ChallengeTokenParser()->{$method}('<div>You must be logged in to view this page.</div>');
})->with(['parseStatus', 'parseClaim'])->throws(ParseException::class);
