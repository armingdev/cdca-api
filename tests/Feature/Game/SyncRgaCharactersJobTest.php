<?php

use App\Game\Auth\CharacterSyncService;
use App\Jobs\RefreshCharacterStatsJob;
use App\Jobs\SyncRgaCharactersJob;
use App\Models\Character;
use App\Models\Rga;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

function fakeAccountsPage(): void
{
    Http::fake([
        'sigil.outwar.com/accounts.php*' => Http::response(sigilAccountsHtml()),
        'torax.outwar.com/accounts.php*' => Http::response('<html></html>'),
    ]);
}

it('never logs a sessionless account in just to read its roster', function () {
    // A game login can boot the session the player is using in their browser,
    // so a background convenience must never trigger one.
    $rga = Rga::factory()->for(User::factory())->create(['cookies' => null]);

    (new SyncRgaCharactersJob($rga))->handle(app(CharacterSyncService::class));

    Http::assertNothingSent();
});

it('reads the roster and stamps the sync time', function () {
    fakeAccountsPage();
    $rga = Rga::factory()->for(User::factory())->withSession()->create();

    Queue::fake();
    (new SyncRgaCharactersJob($rga))->handle(app(CharacterSyncService::class));

    expect($rga->fresh()->characters_synced_at)->not->toBeNull()
        ->and(Character::where('rga_id', $rga->id)->count())->toBeGreaterThan(0);

    Queue::assertPushed(RefreshCharacterStatsJob::class);
});

it('skips a roster read that happened recently', function () {
    $rga = Rga::factory()->for(User::factory())->withSession()->create([
        'characters_synced_at' => now()->subMinutes(5),
    ]);

    (new SyncRgaCharactersJob($rga))->handle(app(CharacterSyncService::class));

    Http::assertNothingSent();
});

it('reads again once the debounce window has passed', function () {
    fakeAccountsPage();
    Queue::fake();

    $rga = Rga::factory()->for(User::factory())->withSession()->create([
        'characters_synced_at' => now()->subHours(12),
    ]);

    (new SyncRgaCharactersJob($rga))->handle(app(CharacterSyncService::class));

    expect($rga->fresh()->characters_synced_at->diffInMinutes(now(), true))->toBeLessThan(1);
});

it('leaves the roster alone when the game answers with nonsense', function () {
    Http::fake(['*accounts.php*' => Http::response('<html>maintenance</html>')]);

    $rga = Rga::factory()->for(User::factory())->withSession()->create();
    Queue::fake();

    // A page that yields no rows must not invent, delete, or fail anything —
    // this runs in the background behind a login, so it stays quiet.
    (new SyncRgaCharactersJob($rga))->handle(app(CharacterSyncService::class));

    expect(Character::where('rga_id', $rga->id)->count())->toBe(0);
    Queue::assertNotPushed(RefreshCharacterStatsJob::class);
});
