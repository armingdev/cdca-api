<?php

use App\Game\Auth\LoginService;
use App\Game\Enums\RunEventType;
use App\Game\Enums\RunStatus;
use App\Game\GameClock;
use App\Models\Character;
use App\Models\Potion;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\RunParticipant;
use Database\Seeders\PotionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    seedCombatWorld();
    $this->seed(PotionSeeder::class);
    $this->kix = Potion::where('name', 'Kix Potion')->sole();
    $this->ragePotion = Potion::where('name', 'Rage Potion')->sole();
    $this->character = Character::factory()->for(Rga::factory()->withSession())->create();
});

/**
 * @return list<array<string, mixed>> the activate POSTs, as sent
 */
function potionActivations(): array
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), 'backpack_action.php'))
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

it('keeps a selected buff potion active before combat', function () {
    fakePotionEndpoints();
    fakeCombatWorld();

    $participant = RunParticipant::factory()
        ->for(Run::factory()->state([
            'status' => RunStatus::Running,
            'config' => ['mob_names' => ['Kix Harvester'], 'run_count' => 1],
            'buff_potion_ids' => [$this->kix->id],
        ]))
        ->for($this->character)
        ->create();

    makeRunJob($participant)->handle(app(LoginService::class));

    $used = RunEvent::where('type', RunEventType::PotionUsed)->sole();

    expect($participant->fresh()->status)->toBe(RunStatus::Completed)
        ->and($participant->fresh()->wins)->toBe(1)
        ->and(potionActivations())->toBe([['action' => 'activate', 'itemids' => ['1794073122']]])
        ->and($used->message)->toBe('Drank Kix Potion (95 left).');
});

describe('rage potions', function () {
    beforeEach(function () {
        $this->rageRun = fn (array $progress = [], int $max = 2) => RunParticipant::factory()
            ->for(Run::factory()->state([
                'status' => RunStatus::Running,
                // The mob costs 150 rage and the character holds 100: the game's
                // price is out of reach, which used to mean "wait for the tick".
                'config' => ['mob_names' => ['Kix Harvester'], 'stop_rage' => 0],
                'rage_potion_ids' => [$this->ragePotion->id],
                'max_rage_potions' => $max,
            ]))
            ->for($this->character)
            ->create(['progress' => $progress]);
    });

    it('drinks one instead of waiting for the rage tick, and comes straight back', function () {
        $this->freezeSecond();
        fakePotionEndpoints(activateResponse: '{"status":"Rage Potion activated!<br>","redirectTo":""}');
        fakeCombatWorld(rage: 100);
        $participant = ($this->rageRun)();

        makeRunJob($participant)->handle(app(LoginService::class));

        $participant->refresh();
        $used = RunEvent::where('type', RunEventType::PotionUsed)->sole();

        expect($participant->status)->toBe(RunStatus::Waiting)
            ->and($participant->resume_at->equalTo(now()->addSeconds(5)))->toBeTrue()
            ->and($participant->progress['rage_potions_used'])->toBe(1)
            ->and($participant->last_activity)->toContain('Drank Rage Potion for rage (1/2)')
            ->and(potionActivations())->toBe([['action' => 'activate', 'itemids' => ['8240135']]])
            ->and($used->context)->toMatchArray(['potion' => 'Rage Potion', 'rage_potions_used' => 1, 'max_rage_potions' => 2]);
    });

    it('waits for the rage tick once the run\'s potion budget is spent', function () {
        $this->freezeSecond();
        fakePotionEndpoints();
        fakeCombatWorld(rage: 100);
        $participant = ($this->rageRun)(['rage_potions_used' => 2]);

        makeRunJob($participant)->handle(app(LoginService::class));

        $participant->refresh();

        expect($participant->status)->toBe(RunStatus::Waiting)
            ->and($participant->resume_at->equalTo(GameClock::nextRageTickAt()))->toBeTrue()
            ->and($participant->last_activity)->toContain('Waiting for rage')
            ->and(potionActivations())->toBe([]);
    });

    it('waits for the rage tick when none is left, and says so', function () {
        $this->freezeSecond();
        // A tab holding only Kix: the selected Rage Potion is used up.
        $tab = '<img data-itemidqty="96" data-name="Kix Potion" data-itemqty="" data-itemid="2063" data-iid="1794073122" id="item0" class="itemimage backpackslot" src="/images/potion28.jpg" onclick="kill();makemenu(this,event,100,\'adzcvs\',\'\',\'0\',\'1794073122\',\'2403\', \'0\');">'
            .'<span id="backpackmaxval" data-maxval="-1" data-isover="" data-curitemct="1"></span>';
        fakePotionEndpoints(tab: $tab);
        fakeCombatWorld(rage: 100);
        $participant = ($this->rageRun)();

        makeRunJob($participant)->handle(app(LoginService::class));

        $participant->refresh();

        expect($participant->resume_at->equalTo(GameClock::nextRageTickAt()))->toBeTrue()
            ->and(potionActivations())->toBe([])
            ->and(RunEvent::where('type', RunEventType::PotionSkipped)->sole()->message)
            ->toBe('No selected rage potion left — waiting for rage instead.');
    });
});
