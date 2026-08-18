<?php

use App\Game\Parsers\TeleportDestinationsParser;

it('parses the destination dropdown out of skills_info.php?id=27', function () {
    $destinations = new TeleportDestinationsParser()->parse(gameFixture('skills/skills_info_teleport.html'));

    expect($destinations)->toHaveCount(17)
        ->and($destinations[0]->roomId)->toBe(134)
        ->and($destinations[0]->name)->toBe('Sewers Entrance')
        ->and($destinations[16]->roomId)->toBe(5006)
        ->and($destinations[16]->name)->toBe('Tuntentian Service Wing');
});

it('keeps both rooms that share a destination name', function () {
    $destinations = new TeleportDestinationsParser()->parse(gameFixture('skills/skills_info_teleport.html'));

    $chuggers = array_values(array_filter(
        $destinations,
        fn ($destination): bool => $destination->name === 'Chuggers Palace Bar',
    ));

    expect($chuggers)->toHaveCount(2)
        ->and(array_map(fn ($destination): int => $destination->roomId, $chuggers))->toBe([231, 299]);
});

it('decodes entities in destination names', function () {
    $destinations = new TeleportDestinationsParser()->parse(gameFixture('skills/skills_info_teleport.html'));

    $names = array_map(fn ($destination): string => $destination->name, $destinations);

    expect($names)->toContain("Blackheart's Bank")
        ->and($names)->toContain("Ken'Drals Castle");
});

it('returns nothing for a skill detail without a destination form', function () {
    $destinations = new TeleportDestinationsParser()->parse(gameFixture('skills/skills_info_trained_recharging.html'));

    expect($destinations)->toBeEmpty();
});
