<?php

use App\Game\Auth\CharacterSyncService;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

function sigilAccountsHtml(int $level = 85): string
{
    return <<<HTML
    <table><tr>
      <td><font color="#FFFF00"><b>RealLinuXX</b></font></td>
      <td><font color="#FFFFFF"><b>{$level}</b></font></td>
      <td><font color="#999999"><b>Collective 2</b></font></td>
      <td><a href="http://sigil.outwar.com/world.php?suid=2403&serverid=1"><b>PLAY!</b></a></td>
    </tr></table>
    HTML;
}

it('discovers characters on both servers and upserts them', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'sigil.outwar.com/accounts.php*' => Http::response(sigilAccountsHtml()),
        'torax.outwar.com/accounts.php*' => Http::response(gameFixture('accounts_enumeration.html')),
    ]);

    $characters = app(CharacterSyncService::class)->sync($rga);

    expect($characters)->toHaveCount(2)
        ->and(Character::count())->toBe(2);

    $sigil = Character::where('server_id', 1)->first();
    $torax = Character::where('server_id', 2)->first();

    expect($sigil->suid)->toBe(2403)
        ->and($sigil->name)->toBe('RealLinuXX')
        ->and($sigil->level)->toBe(85)
        ->and($sigil->rga_id)->toBe($rga->id)
        ->and($torax->suid)->toBe(21980)
        ->and($torax->name)->toBe('LinuXX')
        ->and($torax->crew)->toBe('LinuXXisl33t');
});

it('updates existing characters instead of duplicating them', function () {
    $rga = Rga::factory()->withSession()->create();

    Http::fake([
        'sigil.outwar.com/accounts.php*' => Http::response(sigilAccountsHtml(86)),
        'torax.outwar.com/accounts.php*' => Http::response('<html></html>'),
    ]);

    Character::factory()->for($rga)->create(['suid' => 2403, 'server_id' => 1, 'level' => 85]);

    app(CharacterSyncService::class)->sync($rga);

    expect(Character::count())->toBe(1)
        ->and(Character::first()->level)->toBe(86);
});
