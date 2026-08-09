<?php

use App\Game\Engine\MobRunConfig;
use App\Game\Engine\MobRunner;
use App\Game\Items\GearManager;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

/**
 * The three equippable items in the captured backpack fixture (menu flags
 * containing `e`); every other item in it is a non-equippable augment.
 */
const SHIELD_IID = 7680408;        // Bone-Forge

const PROTOTYPE_IID = 1557857102;  // Radiation Prototype

const BELT_IID = 1798106185;       // Gem Stone Belt

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    $this->character = Character::factory()->for(Rga::factory()->withSession())->create();
});

/**
 * An item_rollover tooltip shaped like the captured one: name, bracket tags,
 * then the stat lines.
 */
function rolloverHtml(string $name, string $slot, int $requiredLevel, int $atk, int $hp = 0): string
{
    return '<table id="itemtable"><tr><td>'.$name.'</td></tr><tr><td>'
        ."[Required Level {$requiredLevel}]<br/>[Slot - {$slot}]<br/><br>+{$atk} ATK<br>+{$hp} HP<br>"
        .'</td></tr></table>';
}

/**
 * A paper doll wearing one item: enough to make a slot "occupied".
 */
function equipmentPageWith(int $iid, string $name, int $slotId): string
{
    return '<div><img src="/images/items/x.gif" ONMOUSEOVER="itempopup(event,\''.$iid.'\')" ONMOUSEOUT="kill()"'
        .' onclick="removeItem(\''.$iid.'\',2403,0);document.getElementById(\'slot'.$slotId.'\').innerHTML=\'\'"'
        .' alt="'.$name.'"></div>';
}

/**
 * Fake the backpack tab, the equipment doll and a rollover per iid.
 * $rollovers maps iid → HTML; an iid with no entry answers an empty tooltip,
 * which the parser rejects. $equipment defaults to a naked character.
 *
 * @param  array<int, string>  $rollovers
 */
function fakeBackpack(array $rollovers, string $actionResponse = '{"status":"ok"}', ?string $equipment = null): void
{
    Http::fake(function ($request) use ($rollovers, $actionResponse, $equipment) {
        $url = $request->url();

        if (str_contains($url, 'equipment.php')) {
            return Http::response($equipment ?? gameFixture('equipment_page_naked.html'));
        }

        if (str_contains($url, 'backpackcontents.php')) {
            return Http::response(gameFixture('backpack_contents.html'));
        }

        if (str_contains($url, 'item_rollover.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return Http::response($rollovers[(int) $query['id']] ?? '');
        }

        if (str_contains($url, 'backpack_action.php')) {
            return Http::response($actionResponse);
        }

        return Http::response('<html>world</html>');
    });
}

/**
 * @return list<int>
 */
function equipAttempts(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'backpack_action.php'))
        ->map(fn (array $pair) => $pair[0]['itemids'])
        ->flatten()
        ->map(fn ($iid) => (int) $iid)
        ->values()
        ->all();
}

function timesRequestedInGearTest(string $needle): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), $needle))
        ->count();
}

function rolloversRequested(): int
{
    return timesRequestedInGearTest('item_rollover.php');
}

/**
 * @return array<int, string>
 */
function threeWearableItems(): array
{
    return [
        SHIELD_IID => rolloverHtml('Bone-Forge', 'Shield', 60, atk: 100),
        PROTOTYPE_IID => rolloverHtml('Radiation Prototype', 'Shield', 60, atk: 200),
        BELT_IID => rolloverHtml('Gem Stone Belt', 'Belt', 60, atk: 50),
    ];
}

it('equips the best-scoring item in each slot', function () {
    fakeBackpack(threeWearableItems());

    $summary = GearManager::forCharacter($this->character)->optimize(characterLevel: 60);

    expect($summary->scanned)->toBe(18)
        ->and($summary->equipped)->toBe(2)
        ->and($summary->equippedNames)->toContain('Radiation Prototype', 'Gem Stone Belt')
        ->and(equipAttempts())->toEqualCanonicalizing([PROTOTYPE_IID, BELT_IID]);
});

it('skips items the character is too low level to wear', function () {
    fakeBackpack([
        SHIELD_IID => rolloverHtml('Bone-Forge', 'Shield', 60, atk: 100),
        PROTOTYPE_IID => rolloverHtml('Radiation Prototype', 'Shield', 60, atk: 200),
        BELT_IID => rolloverHtml('Gem Stone Belt', 'Belt', 5, atk: 50),
    ]);

    $summary = GearManager::forCharacter($this->character)->optimize(characterLevel: 10);

    expect($summary->equipped)->toBe(1)
        ->and($summary->equippedNames)->toBe(['Gem Stone Belt'])
        ->and(equipAttempts())->toBe([BELT_IID]);
});

it('never reads a rollover for an item it cannot equip', function () {
    fakeBackpack(threeWearableItems());

    GearManager::forCharacter($this->character)->optimize(characterLevel: 60);

    expect(rolloversRequested())->toBe(3);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'item_rollover.php')
        && str_contains($request->url(), 'id=10748385')); // an Augment
});

it('re-reads nothing and re-equips nothing on a second pass over the same backpack', function () {
    fakeBackpack(threeWearableItems());

    $manager = GearManager::forCharacter($this->character);
    $manager->optimize(characterLevel: 60);
    $second = $manager->optimize(characterLevel: 60);

    expect($second->equipped)->toBe(0)
        ->and(equipAttempts())->toHaveCount(2)
        ->and(rolloversRequested())->toBe(3);
});

it('swaps in a stronger item that drops mid-run', function () {
    $dropIid = 1900000001;
    $contents = gameFixture('backpack_contents.html');
    $withDrop = $contents
        .'<img data-itemidqty="1" data-name="Warlord Blade" data-iid="'.$dropIid.'" class="itemimage backpackslot"'
        .' src="/images/items/blade.gif" alt="Warlord Blade"'
        ." onclick=\"kill();makemenu(this,event,100,'edzcvs','','19','{$dropIid}','7257', '1');\">";

    Http::fake(function ($request) use (&$contents, $dropIid) {
        $url = $request->url();

        if (str_contains($url, 'backpackcontents.php')) {
            return Http::response($contents);
        }

        if (str_contains($url, 'item_rollover.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return Http::response(match ((int) $query['id']) {
                SHIELD_IID => rolloverHtml('Bone-Forge', 'Shield', 60, atk: 100),
                PROTOTYPE_IID => rolloverHtml('Radiation Prototype', 'Weapon', 60, atk: 80),
                BELT_IID => rolloverHtml('Gem Stone Belt', 'Belt', 60, atk: 50),
                $dropIid => rolloverHtml('Warlord Blade', 'Weapon', 60, atk: 500),
                default => '',
            });
        }

        return Http::response('{"status":"ok"}');
    });

    $manager = GearManager::forCharacter($this->character);
    $manager->optimize(characterLevel: 60);

    $contents = $withDrop; // the fight dropped a better weapon
    $second = $manager->optimize(characterLevel: 60);

    expect($second->equippedNames)->toBe(['Warlord Blade'])
        ->and(equipAttempts())->toBe([SHIELD_IID, PROTOTYPE_IID, BELT_IID, $dropIid]);
});

it('keeps equipping the other slots when the game refuses one item', function () {
    fakeBackpack(threeWearableItems(), actionResponse: '{"status":"error"}');

    $summary = GearManager::forCharacter($this->character)->optimize(characterLevel: 60);

    // Both slots were attempted; neither counts as worn.
    expect($summary->equipped)->toBe(0)
        ->and(equipAttempts())->toHaveCount(2);
});

it('ignores an item whose tooltip cannot be read', function () {
    fakeBackpack([PROTOTYPE_IID => rolloverHtml('Radiation Prototype', 'Shield', 60, atk: 200)]);

    $summary = GearManager::forCharacter($this->character)->optimize(characterLevel: 60);

    expect($summary->equipped)->toBe(1)
        ->and(equipAttempts())->toBe([PROTOTYPE_IID]);
});

it('swaps out worn gear the backpack can beat', function () {
    $wornIid = 1400000001;

    fakeBackpack(
        rollovers: [
            PROTOTYPE_IID => rolloverHtml('Radiation Prototype', 'Weapon', 60, atk: 200),
            $wornIid => rolloverHtml('Rusty Blade', 'Weapon', 60, atk: 10),
        ],
        equipment: equipmentPageWith($wornIid, 'Rusty Blade', slotId: 3),
    );

    $summary = GearManager::forCharacter($this->character)->optimize(characterLevel: 60);

    // The game auto-swaps, so equipping the winner is the whole operation.
    expect($summary->equippedNames)->toBe(['Radiation Prototype'])
        ->and(equipAttempts())->toBe([PROTOTYPE_IID]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'backpack_action.php')
        && $request['action'] === 'unequip');
});

it('leaves worn gear alone when the backpack has nothing better', function () {
    $wornIid = 1400000002;

    fakeBackpack(
        rollovers: [
            PROTOTYPE_IID => rolloverHtml('Radiation Prototype', 'Weapon', 60, atk: 200),
            $wornIid => rolloverHtml('Warlord Blade', 'Weapon', 60, atk: 900),
        ],
        equipment: equipmentPageWith($wornIid, 'Warlord Blade', slotId: 3),
    );

    $summary = GearManager::forCharacter($this->character)->optimize(characterLevel: 60);

    expect($summary->equipped)->toBe(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'backpack_action.php'));
});

it('reads the equipment doll once and only prices the contested slots', function () {
    $wornIid = 1400000003;

    fakeBackpack(
        rollovers: threeWearableItems() + [$wornIid => rolloverHtml('Rusty Blade', 'Weapon', 60, atk: 10)],
        equipment: equipmentPageWith($wornIid, 'Rusty Blade', slotId: 3),
    );

    $manager = GearManager::forCharacter($this->character);
    $manager->optimize(characterLevel: 60);
    $manager->optimize(characterLevel: 60);

    // The worn weapon competes with nothing (candidates are a shield and a
    // belt), so it never costs a tooltip; the doll is read once regardless.
    expect(timesRequestedInGearTest('equipment.php'))->toBe(1)
        ->and(rolloversRequested())->toBe(3);
});

it('equips before the first attack of a smart run', function () {
    seedCombatWorld();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'backpackcontents.php')) {
            return Http::response(gameFixture('backpack_contents.html'));
        }

        if (str_contains($url, 'item_rollover.php')) {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return Http::response((int) $query['id'] === PROTOTYPE_IID
                ? rolloverHtml('Radiation Prototype', 'Weapon', 1, atk: 200)
                : '');
        }

        if (str_contains($url, 'backpack_action.php')) {
            return Http::response('{"status":"ok"}');
        }

        return null; // fall through to the combat world faked below
    });

    fakeCombatWorld();

    MobRunner::forCharacter($this->character, new MobRunConfig(
        mobNames: ['Kix Harvester'],
        smart: true,
    ))->run();

    $urls = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->url());
    $equippedAt = $urls->search(fn (string $url) => str_contains($url, 'backpack_action.php'));
    $attackedAt = $urls->search(fn (string $url) => str_contains($url, 'somethingelse.php'));

    expect($equippedAt)->not->toBeFalse()
        ->and($attackedAt)->not->toBeFalse()
        ->and($equippedAt)->toBeLessThan($attackedAt);
});
