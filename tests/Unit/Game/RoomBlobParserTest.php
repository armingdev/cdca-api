<?php

use App\Game\Data\RoomBlob;
use App\Game\Exceptions\ParseException;
use App\Game\Parsers\RoomBlobParser;

it('parses the captured room blob fixture', function () {
    $blob = new RoomBlobParser()->parse(gameFixture('room_blob.json'));

    expect($blob)->toBeInstanceOf(RoomBlob::class)
        ->and($blob->curRoom)->toBe(31954)
        ->and($blob->name)->toBe('Veldara Woods')
        ->and($blob->exits)->toBe(['west' => 31955])
        ->and($blob->doors)->toBeNull()
        ->and($blob->hasError())->toBeFalse()
        ->and($blob->mobs)->toHaveCount(1);

    $mob = $blob->mobs[0];

    expect($mob->name)->toBe('Gregov, Knight of the Woods')
        ->and($mob->level)->toBe(120)
        ->and($mob->rageCost)->toBe(400)
        ->and($mob->mobId)->toBe(4387)
        ->and($mob->spawnId)->toBe(247543)
        ->and($mob->encid)->toBe('O330C342F360K348A342W336')
        ->and($mob->hash)->toBe('49c7d2191fdba1ec8e15c8595b6291ce')
        ->and($mob->isDead)->toBeFalse()
        ->and($mob->isRaid())->toBeTrue()
        ->and($mob->lastKilledBy)->toBe('My Balls Your Mouth');
});

it('parses the raw wire capture identically', function () {
    $wire = json_decode(gameFixture('ajax_changeroomb_room0.json'), true);

    $blob = new RoomBlobParser()->parse($wire['body']);

    expect($blob->curRoom)->toBe(31954)
        ->and($blob->exits)->toBe(['west' => 31955]);
});

it('treats "0" exits as no exit', function () {
    $blob = new RoomBlobParser()->parse(json_encode([
        'curRoom' => '11',
        'name' => 'Test',
        'north' => '12',
        'south' => '40',
        'east' => '41',
        'west' => '0',
        'roomDetailsNew' => [],
    ]));

    expect($blob->exits)->toBe(['north' => 12, 'east' => 41, 'south' => 40])
        ->and($blob->neighborIds())->toBe([12, 41, 40])
        ->and($blob->exitTo(41))->toBeTrue()
        ->and($blob->exitTo(99))->toBeFalse();
});

it('throws on a non-json body', function () {
    new RoomBlobParser()->parse('<html>Rampid Gaming Login</html>');
})->throws(ParseException::class);
