<?php

use App\Game\Enums\PotionKind;
use App\Game\Parsers\PotionEffectParser;

it('reads the many ways the catalog states an effect', function (string $name, string $description, PotionKind $kind, ?int $minutes, ?int $rage) {
    $effect = new PotionEffectParser()->parse($name, $description);

    expect($effect->kind)->toBe($kind)
        ->and($effect->durationMinutes)->toBe($minutes)
        ->and($effect->rageAmount)->toBe($rage);
})->with([
    // Real descriptions from database/data/potions.json.
    'for N mins' => ['Kix Potion', 'Gives your character + 425 ATK, 1,250 HP and 5% Crit for 60 mins.', PotionKind::Buff, 60, null],
    'Duration: N hours' => ['Remnant Solice Lev 9', '+750 ATK +1500 HP +25 Chaos damage Duration: 4 hours', PotionKind::Buff, 240, null],
    '(N minute duration)' => ['Olympian Push', '+350 Atk +100 Arcane Damage (60 minute duration)', PotionKind::Buff, 60, null],
    'Lasts a word-number' => ['Ghoulish Potion', 'Gives +100 rage/turn and 125 attack power. Lasts two hours...', PotionKind::Buff, 120, null],
    'glued HitDuration' => ['Exalted Brew', '1100 atk, 1600 hp, 600 Kinetic Damage, 10% Critical HitDuration: 240 mins', PotionKind::Buff, 240, null],
    'days' => ['Flask of Endurance', '+750 ATK+1500 HP+200 Rage Per Turn Duration: 7 days', PotionKind::Buff, 10080, null],
    'per-hour rate is not a duration' => ['Jar of Clarity', '500 exp/hr (Duration: 8 hours)', PotionKind::Buff, 480, null],
    'buff that also gives rage' => ['Star Power', 'Pull up on your guitar for +750 ATK, +5% Crit, and 2,500 extra rage! Duration: 60 minutes', PotionKind::Buff, 60, 2500],
    'instant rage' => ['Rage Potion', 'Warning: Awards 1,000 Rage upon consumption.', PotionKind::Rage, null, 1000],
    'rage refill' => ['Recharge the Fury', "Restores your character's rage to the maximum.", PotionKind::Rage, null, null],
    'rage per turn alone is not rage' => ['Tracking Salve', '+300 Atk, +300 HP, + 300 Rage/turn. 2 Hour Duration.', PotionKind::Buff, 120, null],
    'quest potion' => ['Arcane Potion', 'Used to kill Lord Narada and other Potion Gods.', PotionKind::Other, null, null],
    'next 3 attacks has no duration' => ['Brutality Potion', 'For the next 3 successful attacks you will double strip/steal your targets experience.', PotionKind::Other, null, null],
    'never auto: PvP immunity' => ['PvP Immunity (7 Days)', 'Cannot attack or be attacked by players for 7 days.', PotionKind::Other, 10080, null],
]);

it('classifies the whole harvested catalog', function () {
    $parser = new PotionEffectParser;
    $potions = json_decode(file_get_contents(__DIR__.'/../../../database/data/potions.json'), true);

    $kinds = collect($potions)->countBy(fn (array $potion) => $parser->parse($potion['name'], $potion['description'])->kind->value);

    expect($potions)->toHaveCount(139)
        ->and($kinds->all())->toEqual(['buff' => 95, 'rage' => 13, 'other' => 31]);

    foreach ($potions as $potion) {
        $effect = $parser->parse($potion['name'], $potion['description']);

        if (preg_match('/Rage (Potion|Tonic)$/', $potion['name'])) {
            expect($effect->kind)->toBe(PotionKind::Rage, $potion['name'])
                ->and($effect->rageAmount)->toBeGreaterThan(0);
        }

        if ($effect->kind === PotionKind::Buff) {
            expect($effect->durationMinutes)->toBeGreaterThan(0, $potion['name']);
        }
    }
});
