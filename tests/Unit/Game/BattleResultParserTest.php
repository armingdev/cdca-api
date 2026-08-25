<?php

use App\Game\Enums\BattleOutcome;
use App\Game\Exceptions\ParseException;
use App\Game\Parsers\BattleResultParser;

it('parses the captured win fixture', function () {
    $result = new BattleResultParser()->parse(gameFixture('battle_result_vars.js'));

    expect($result->outcome)->toBe(BattleOutcome::Win)
        ->and($result->attackerName)->toBe('RealLinuXX')
        ->and($result->defenderName)->toBe('Pristine Blader')
        ->and($result->expGained)->toBe(1001)
        ->and($result->goldGained)->toBe(125)
        ->and($result->statGains)->toBe(['strength' => 15])
        ->and($result->dropName)->toBeNull();
});

it('classifies a loss by the weakened-with-no-exp rule from the captured loss', function () {
    // battle_result text from samples/battle_outcomes.json (captured loss).
    $html = 'var battle_result = "Grand Sole Protector has weakened LinuXX_2 by 2";'
        .'var attacker_result = "Win!";'; // the lying template default

    $result = new BattleResultParser()->parse($html);

    expect($result->outcome)->toBe(BattleOutcome::Loss)
        ->and($result->expGained)->toBeNull();
});

it('does not classify a win with incidental weakening as a loss', function () {
    $html = 'var battle_result = "Mob has weakened Hero by 3<br>Hero has gained 676 experience!";';

    $result = new BattleResultParser()->parse($html);

    expect($result->outcome)->toBe(BattleOutcome::Win)
        ->and($result->expGained)->toBe(676);
});

it('extracts drops from the found_items div', function () {
    $html = gameFixture('battle_result_vars.js')
        .'<div id="found_items"><font size="3"><b>WIN: Found Thief Dagger</b></font></div>';

    expect(new BattleResultParser()->parse($html)->dropName)->toBe('Thief Dagger');
});

it('marks unclassifiable battle text as unknown', function () {
    $result = new BattleResultParser()->parse('var battle_result = "Something entirely new happened";');

    expect($result->outcome)->toBe(BattleOutcome::Unknown);
});

it('throws when the page has no battle_result var', function () {
    new BattleResultParser()->parse('<html>That mob is already dead!</html>');
})->throws(ParseException::class);

it('classifies a live PvP fight from the server success flag', function () {
    // VERIFIED 2026-08-25. The page emits BOTH outcome branches as literal JS
    // and selects at runtime, so the first `attacker_result = "Win!"` in the
    // source is dead code. `var successful` is the only honest signal.
    $result = new BattleResultParser()->parse(gameFixture('plrattack_loss.html'));

    expect($result->outcome)->toBe(BattleOutcome::Loss)
        ->and($result->attackerName)->toBe('RealLinuXX')
        ->and($result->defenderName)->toBe('Krongstein');
});

it('does not trust the first attacker_result assignment on the page', function () {
    $html = gameFixture('plrattack_loss.html');

    // The misleading branch really is present and really does come first.
    expect($html)->toContain('var attacker_result = "Win!"');

    expect(new BattleResultParser()->parse($html)->outcome)->toBe(BattleOutcome::Loss);
});

it('reads a real PvP win from the same flag', function () {
    $result = new BattleResultParser()->parse(gameFixture('plrattack_win.html'));

    expect($result->outcome)->toBe(BattleOutcome::Win)
        ->and($result->attackerName)->toBe('RealLinuXX')
        ->and($result->defenderName)->toBe('azraid5');
});

it('classifies a PvP win whose text carries neither experience nor "has weakened"', function () {
    // VERIFIED 2026-08-25. This win reads
    //   "RAMPAGE!<br>RealLinuXX takes pity on azraid5<br>"
    // — no exp gain and no "has weakened", so under the PvE-derived rules it
    // came out Unknown. That is what produced 115 unknowns in a 135-attack run.
    $result = new BattleResultParser()->parse(gameFixture('plrattack_win.html'));

    expect($result->rawBattleResult)
        ->not->toContain('has weakened')
        ->not->toContain('experience');

    expect($result->outcome)->toBe(BattleOutcome::Win)
        ->and($result->expGained)->toBeNull()
        ->and($result->goldGained)->toBeNull();
});

it('classifies every captured PvP page from the flag alone', function (
    string $fixture,
    BattleOutcome $outcome,
    string $defender,
) {
    // Three real pages, three different opponents, two outcomes. None of them
    // can be classified from the prose: the win carries no marker at all, and
    // both losses carry "has weakened", which also appears on wins.
    $result = new BattleResultParser()->parse(gameFixture($fixture));

    expect($result->outcome)->toBe($outcome)
        ->and($result->attackerName)->toBe('RealLinuXX')
        ->and($result->defenderName)->toBe($defender)
        // No PvP fight of either outcome awards gold.
        ->and($result->goldGained)->toBeNull();
})->with([
    'win vs azraid5' => ['plrattack_win.html', BattleOutcome::Win, 'azraid5'],
    'win vs NoName003' => ['plrattack_win_stripped.html', BattleOutcome::Win, 'NoName003'],
    'loss vs Krongstein' => ['plrattack_loss.html', BattleOutcome::Loss, 'Krongstein'],
    'loss vs StarPower' => ['plrattack_loss_starpower.html', BattleOutcome::Loss, 'StarPower'],
]);

it('reads the experience a PvP win strips and gains', function () {
    // VERIFIED 2026-08-25. PvP words its reward as
    //   "RealLinuXX stripped 14484xp and gained 14484xp"
    // — nothing like the PvE "has gained N experience!", and the numbers are
    // wrapped in <font> tags, so both old patterns missed and every PvP win
    // recorded null exp.
    $result = new BattleResultParser()->parse(gameFixture('plrattack_win_stripped.html'));

    expect($result->outcome)->toBe(BattleOutcome::Win)
        ->and($result->expGained)->toBe(14484)
        ->and($result->expStripped)->toBe(14484)
        ->and($result->defenderName)->toBe('NoName003');
});

it('records no experience for a win against a target too weak to strip', function () {
    // "takes pity on" — the level-95 vs level-11 case.
    $result = new BattleResultParser()->parse(gameFixture('plrattack_win.html'));

    expect($result->outcome)->toBe(BattleOutcome::Win)
        ->and($result->expGained)->toBeNull()
        ->and($result->expStripped)->toBeNull();
});

it('still reads the PvE experience phrasing, which is different again', function () {
    $html = 'var battle_result = "Hero has gained 676 experience!<br>Hero gained 40 gold!";';
    $result = new BattleResultParser()->parse($html);

    expect($result->expGained)->toBe(676)
        ->and($result->goldGained)->toBe(40)
        ->and($result->expStripped)->toBeNull();
});

it('reads a loss the same way regardless of which opponent inflicted it', function () {
    // Both losses phrase it "{defender} has weakened {attacker} by {N}".
    foreach (['plrattack_loss.html', 'plrattack_loss_starpower.html'] as $fixture) {
        $result = new BattleResultParser()->parse(gameFixture($fixture));

        expect($result->rawBattleResult)->toContain('has weakened RealLinuXX')
            ->and($result->outcome)->toBe(BattleOutcome::Loss);
    }
});

it('still uses the PvE rules when a page carries no success flag', function () {
    $win = 'var battle_result = "Hero has gained 676 experience!";';
    $loss = 'var battle_result = "Mob has weakened Hero by 2<br>";';

    expect(new BattleResultParser()->parse($win)->outcome)->toBe(BattleOutcome::Win)
        ->and(new BattleResultParser()->parse($loss)->outcome)->toBe(BattleOutcome::Loss);
});
