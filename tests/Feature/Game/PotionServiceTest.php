<?php

use App\Game\Enums\PotionActivationOutcome;
use App\Game\Enums\PotionKind;
use App\Game\Items\PotionService;
use App\Models\Character;
use App\Models\CharacterPotion;
use App\Models\Potion;
use App\Models\Rga;
use Database\Seeders\PotionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    $this->seed(PotionSeeder::class);
    $this->character = Character::factory()->for(Rga::factory()->withSession())->create();
    $this->kix = Potion::where('name', 'Kix Potion')->sole();
    $this->freezeSecond();
});

function activationRequests(): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), 'backpack_action.php'))->count();
}

it('stores what the potion tab holds and how long the active ones have left', function () {
    fakePotionEndpoints();
    Http::fake(['*cast_skills.php*' => Http::response(gameFixture('potions/cast_skills_potion_active.html'))]);
    $gone = CharacterPotion::factory()->for($this->character)->create(['quantity' => 3]);

    $stacks = PotionService::forCharacter($this->character)->sync();

    $kix = $stacks->get($this->kix->id);
    $rage = $stacks->get(Potion::where('name', 'Rage Potion')->value('id'));

    expect($stacks->where('quantity', '>', 0))->toHaveCount(43)
        ->and($kix->quantity)->toBe(96)
        ->and($kix->iid)->toBe(1794073122)
        // "1 hour 2 mins left" in the captured Current Effects panel.
        ->and($kix->active_until->equalTo(now()->addMinutes(62)))->toBeTrue()
        ->and($kix->isActive())->toBeTrue()
        ->and($rage->quantity)->toBe(5)
        ->and($rage->active_until)->toBeNull()
        ->and($rage->active_synced_at->equalTo(now()))->toBeTrue()
        // A stack no longer in the tab is kept, at zero.
        ->and($gone->fresh()->quantity)->toBe(0);
});

it('drinks the stack\'s current item and estimates the window until the next read', function () {
    fakePotionEndpoints();

    $result = PotionService::forCharacter($this->character)->activate($this->kix);

    expect($result->outcome)->toBe(PotionActivationOutcome::Activated)
        ->and($result->message)->toBe('Kix Potion activated!')
        ->and($result->stack->quantity)->toBe(95)
        ->and($result->stack->last_activated_at->equalTo(now()))->toBeTrue()
        ->and($result->stack->active_until->equalTo(now()->addMinutes(60)))->toBeTrue();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'backpack_action.php')
        && $request['action'] === 'activate'
        && $request['itemids'] === ['1794073122']);
});

it('consumes nothing when the potion is still active, and asks the game for its time left', function () {
    fakePotionEndpoints(activateResponse: '{"error":"You have already consumed one of these potions, please wait until it wears off"}');
    Http::fake(['*cast_skills.php*' => Http::response(gameFixture('potions/cast_skills_potion_active.html'))]);

    $result = PotionService::forCharacter($this->character)->activate($this->kix);

    expect($result->outcome)->toBe(PotionActivationOutcome::AlreadyActive)
        ->and($result->stack->quantity)->toBe(96)
        ->and($result->stack->active_until->equalTo(now()->addMinutes(62)))->toBeTrue()
        ->and($result->stack->last_activated_at)->toBeNull();
});

it('does not try to drink a potion the tab does not hold', function () {
    fakePotionEndpoints();
    $absent = Potion::where('name', 'Arcane Potion')->sole();

    $result = PotionService::forCharacter($this->character)->activate($absent);

    expect($result->outcome)->toBe(PotionActivationOutcome::OutOfStock)
        ->and($result->stack->quantity)->toBe(0)
        ->and(activationRequests())->toBe(0);
});

it('backs off after an answer it does not understand', function () {
    fakePotionEndpoints(activateResponse: '{"error":"You cannot use that here."}');

    $result = PotionService::forCharacter($this->character)->activate($this->kix);

    expect($result->outcome)->toBe(PotionActivationOutcome::Refused)
        ->and($result->message)->toBe('You cannot use that here.')
        ->and($result->stack->quantity)->toBe(96)
        ->and($result->stack->refused_until->equalTo(now()->addMinutes(5)))->toBeTrue();
});

it('learns a potion the catalog does not know from its tooltip', function () {
    $tab = '<img data-itemidqty="2" data-name="Mystery Brew" data-itemqty="" data-itemid="99999" data-iid="4242" id="item0" class="itemimage backpackslot" src="/images/items/brew.jpg" onclick="kill();makemenu(this,event,100,\'adzcvs\',\'\',\'0\',\'4242\',\'2403\', \'0\');">'
        .'<span id="backpackmaxval" data-maxval="-1" data-isover="" data-curitemct="1"></span>';
    fakePotionEndpoints(tab: $tab);
    Http::fake([
        '*item_rollover.php*' => Http::response('<table id="itemtable"><tr><td>Mystery Brew</td></tr><tr><td>[Inventory]<br/>[Slot - Other]<br/><br><span style="font-style:italic;color:#00FF00">click to activate</span><br><br /><div style="font-weight:normal;">+999 ATK for 30 mins.</div></td></tr></table>'),
        '*cast_skills.php*' => Http::response(gameFixture('potions/cast_skills_potion_active.html')),
    ]);

    PotionService::forCharacter($this->character)->sync();

    $learned = Potion::where('game_item_id', 99999)->sole();

    expect($learned->name)->toBe('Mystery Brew')
        ->and($learned->image)->toBe('items/brew.jpg')
        ->and($learned->kind)->toBe(PotionKind::Buff)
        ->and($learned->duration_minutes)->toBe(30)
        ->and(CharacterPotion::where('potion_id', $learned->id)->value('quantity'))->toBe(2);
});
