<?php

use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
    Storage::fake('local');
});

function roomWithNpc(): string
{
    return json_encode([
        'error' => '',
        'curRoom' => '10',
        'name' => 'Diamond City',
        'north' => '0', 'east' => '0', 'south' => '0', 'west' => '0',
        'roomDetailsNew' => [[
            'name' => 'Stella',
            'level' => '10',
            'rage' => '0',
            'h' => 'npchash123',
            'encid' => 'ENC',
            'mobId' => '59293',
            'spawnId' => '888',
            'isDead' => false,
            'type' => 0,
            'canForm' => false,
        ]],
        'doorsData' => null,
    ]);
}

it('captures the questHelper HTML', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*world_questHelper.php*' => Http::response('{"qtable":"<div>quests</div>"}')]);

    $this->artisan('outwar:quest-capture', ['character' => $character->id, '--helper' => true, '--label' => 'test'])
        ->assertSuccessful();

    Storage::disk('local')->assertExists('captures/quest/questhelper_test.html');
    expect(Storage::disk('local')->get('captures/quest/questhelper_test.html'))->toContain('qtable');
});

it('resolves the NPC hash from the current room and captures the popup', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake([
        '*ajax_changeroomb.php*' => Http::response(roomWithNpc()),
        '*mob.php*' => Http::response('<html>Available Quests: Maglious Demise</html>'),
    ]);

    $this->artisan('outwar:quest-capture', ['character' => $character->id, '--npc-name' => 'Stella', '--label' => 'stella'])
        ->assertSuccessful()
        ->expectsOutputToContain('spawnId 888');

    Storage::disk('local')->assertExists('captures/quest/npc_stella.html');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'mob.php')
        && str_contains($request->url(), 'id=888')
        && str_contains($request->url(), 'h=npchash123'));
});

it('captures a mob_talk step view and its finish variant', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*mob_talk.php*' => Http::response('<html>step body</html>')]);

    $this->artisan('outwar:quest-capture', [
        'character' => $character->id,
        '--npc' => '59293',
        '--step' => '3071',
        '--questid' => '672',
        '--finish' => true,
        '--label' => 'q672s3071',
    ])->assertSuccessful();

    Storage::disk('local')->assertExists('captures/quest/step_q672s3071_view.html');
    Storage::disk('local')->assertExists('captures/quest/step_q672s3071_finish.html');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'stepid=3071') && str_contains($request->url(), 'questid=672'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'finish=1'));
});

it('fails when the named NPC is not in the room', function () {
    $character = Character::factory()->for(Rga::factory()->withSession())->create();

    Http::fake(['*ajax_changeroomb.php*' => Http::response(roomWithNpc())]);

    $this->artisan('outwar:quest-capture', ['character' => $character->id, '--npc-name' => 'Ghost'])
        ->assertFailed()
        ->expectsOutputToContain('No mob named');
});
