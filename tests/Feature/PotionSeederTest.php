<?php

use App\Game\Enums\PotionKind;
use App\Models\Potion;
use Database\Seeders\PotionSeeder;

it('seeds the harvested catalog with each potion classified, and re-seeds idempotently', function () {
    $this->seed(PotionSeeder::class);
    $this->seed(PotionSeeder::class);

    $kix = Potion::where('game_item_id', 2063)->sole();
    $rage = Potion::where('game_item_id', 904)->sole();

    expect(Potion::count())->toBe(139)
        ->and($kix->name)->toBe('Kix Potion')
        ->and($kix->kind)->toBe(PotionKind::Buff)
        ->and($kix->duration_minutes)->toBe(60)
        ->and($rage->kind)->toBe(PotionKind::Rage)
        ->and($rage->rage_amount)->toBe(1000);
});
