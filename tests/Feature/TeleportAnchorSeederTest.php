<?php

use App\Game\Enums\TeleportKind;
use App\Models\TeleportAnchor;
use Database\Seeders\SkillSeeder;
use Database\Seeders\TeleportAnchorSeeder;

// Skill destinations reference skills.id, so the catalog has to exist first —
// the same order DatabaseSeeder runs them in.
beforeEach(fn () => new SkillSeeder()->run());

it('seeds the captured teleport catalog with its landing rooms', function () {
    new TeleportAnchorSeeder()->run();

    expect(TeleportAnchor::count())->toBe(45)
        ->and(TeleportAnchor::where('kind', TeleportKind::Item)->count())->toBe(28)
        ->and(TeleportAnchor::where('kind', TeleportKind::Skill)->count())->toBe(17);

    $ward = TeleportAnchor::firstWhere('game_item_id', 4839);
    expect($ward->name)->toBe('Astral Ward')
        ->and($ward->room_id)->toBe(26137)
        ->and($ward->isFree())->toBeTrue()
        ->and($ward->description)->toContain('Teleports you to the entrance of the Astral World.');

    $district = TeleportAnchor::firstWhere('game_item_id', 2257);
    expect($district->required_level)->toBe(50)
        ->and($district->room_id)->toBe(8178);

    $skillDestination = TeleportAnchor::where('kind', TeleportKind::Skill)->firstWhere('room_id', 376);
    expect($skillDestination->name)->toBe('The Drunken Clam')
        ->and($skillDestination->rage_cost)->toBe(100)
        ->and($skillDestination->cooldown_minutes)->toBe(60);
});

it('is idempotent', function () {
    new TeleportAnchorSeeder()->run();
    new TeleportAnchorSeeder()->run();

    expect(TeleportAnchor::count())->toBe(45);
});

it('never clobbers a live observation', function () {
    TeleportAnchor::factory()->create([
        'kind' => TeleportKind::Item,
        'game_item_id' => 4839,
        'name' => 'Astral Ward',
        'room_id' => 99999,
        'source' => 'observed',
    ]);

    new TeleportAnchorSeeder()->run();

    expect(TeleportAnchor::firstWhere('game_item_id', 4839)->room_id)->toBe(99999);
});
