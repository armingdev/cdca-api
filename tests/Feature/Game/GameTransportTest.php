<?php

use App\Game\Http\GameClient;
use App\Game\Http\GameTransport;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
});

it('hands every caller in the process the same handler, so connections are reused', function () {
    $first = app(GameTransport::class)->handler();
    $second = app(GameTransport::class)->handler();

    expect($first)->toBeCallable()
        ->and($second)->toBe($first);
});

it('falls back to a throwaway handler per request when connection reuse is switched off', function () {
    config(['outwar.http.reuse_connections' => false]);

    expect(app(GameTransport::class)->handler())->toBeNull();
});

it('sends game requests through the shared transport', function () {
    $transport = new class extends GameTransport
    {
        public int $handlerRequests = 0;

        public function handler(): ?callable
        {
            $this->handlerRequests++;

            return null;
        }
    };
    app()->instance(GameTransport::class, $transport);
    Http::fake(['sigil.outwar.com/*' => Http::response('<html>quest</html>')]);

    GameClient::forServer(1)->get('show_quest.php', ['quest' => 1]);

    expect($transport->handlerRequests)->toBe(1);
});
