<?php

use App\Game\Data\AttackTarget;
use App\Game\Enums\TargetAttackability;
use App\Models\AttackCooldown;
use App\Models\Character;
use App\Models\Crew;
use App\Models\PlayerCharacter;

it('blocks a target for 60 minutes after an attack', function () {
    $character = Character::factory()->create();

    $cooldown = AttackCooldown::record($character->id, 105387, 'azraid5');

    expect($cooldown->isBlocking())->toBeTrue()
        ->and($cooldown->minutesRemaining())->toBe(60)
        ->and($cooldown->next_attackable_at->toIso8601String())
        ->toBe($cooldown->last_attacked_at->copy()->addHour()->toIso8601String());
});

it('frees the target once the window has passed', function () {
    $character = Character::factory()->create();

    AttackCooldown::record($character->id, 105387, 'azraid5', now()->subMinutes(61));

    $free = AttackCooldown::query()->blocking()->count();

    expect($free)->toBe(0);
});

it('honours a shortened window for characters that can cast Time Warp', function () {
    $character = Character::factory()->create();

    // Time Warp (3017, Affliction) allows a second attack inside the hour.
    $cooldown = AttackCooldown::record($character->id, 105387, 'azraid5', now(), cooldownMinutes: 30);

    expect($cooldown->minutesRemaining())->toBe(30);
});

it('keeps one row per attacker and target, not one per attack', function () {
    $character = Character::factory()->create();

    AttackCooldown::record($character->id, 105387, 'azraid5', now()->subHours(3));
    AttackCooldown::record($character->id, 105387, 'azraid5');

    expect(AttackCooldown::count())->toBe(1)
        ->and(AttackCooldown::first()->isBlocking())->toBeTrue();
});

it('tracks cooldowns separately per attacking character', function () {
    $one = Character::factory()->create();
    $two = Character::factory()->create();

    AttackCooldown::record($one->id, 105387, 'azraid5');

    expect(AttackCooldown::where('character_id', $one->id)->exists())->toBeTrue()
        ->and(AttackCooldown::where('character_id', $two->id)->exists())->toBeFalse();
});

it('remembers targets by player id so a rename does not create a duplicate', function () {
    PlayerCharacter::remember(1, new AttackTarget(playerId: 265, name: 'Krongstein', level: 95));
    PlayerCharacter::remember(1, new AttackTarget(playerId: 265, name: 'RenamedPlayer', level: 95));

    expect(PlayerCharacter::count())->toBe(1)
        ->and(PlayerCharacter::first()->name)->toBe('RenamedPlayer');
});

it('does not let a thinner sighting erase what a richer one already knew', function () {
    // A hitlist knows the level and the attackability verdict.
    PlayerCharacter::remember(1, new AttackTarget(
        playerId: 265,
        name: 'Krongstein',
        level: 95,
        attackability: TargetAttackability::InRange,
    ));

    // A crew roster knows the level but renders no colour.
    PlayerCharacter::remember(1, new AttackTarget(playerId: 265, name: 'Krongstein', level: 95));

    $player = PlayerCharacter::first();

    expect($player->attackability)->toBe(TargetAttackability::InRange)
        ->and($player->level)->toBe(95);
});

it('separates the same player id across the two servers', function () {
    PlayerCharacter::remember(1, new AttackTarget(playerId: 265, name: 'Sigil player'));
    PlayerCharacter::remember(2, new AttackTarget(playerId: 265, name: 'Torax player'));

    expect(PlayerCharacter::count())->toBe(2);
});

it('links tracked targets to their crew', function () {
    $crew = Crew::create(['server_id' => 1, 'game_crew_id' => 17785, 'name' => 'Asylum']);

    PlayerCharacter::remember(1, new AttackTarget(playerId: 267464, name: 'DaInfamousScareCr0w', level: 70), $crew->id);

    expect($crew->members()->count())->toBe(1)
        ->and($crew->members()->first()->name)->toBe('DaInfamousScareCr0w');
});
