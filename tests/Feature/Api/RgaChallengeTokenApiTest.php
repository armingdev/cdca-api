<?php

use App\Models\Character;
use App\Models\Rga;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->rga = Rga::factory()->for($this->user)->withSession()->create();
    Character::factory()->for($this->rga)->create();
});

it('reads the balance live from the game', function () {
    Http::fake(['sigil.outwar.com/ajax/challenge_status.php*' => Http::response(gameFixture('challenge/challenge_status.json'))]);

    $this->getJson("/api/v1/rgas/{$this->rga->id}/challenge-tokens")
        ->assertOk()
        ->assertJsonPath('data.tokens', 30)
        ->assertJsonPath('data.max_tokens', 30)
        ->assertJsonPath('data.purchased_tokens', 5)
        ->assertJsonPath('data.auto_claim_tokens', true);
});

it('claims on demand and reports the game\'s message', function () {
    Http::fake([
        'sigil.outwar.com/ajax/challenge_status.php*' => Http::sequence()
            ->push('{"result":true,"tokens":"10","p_tokens":"0","timeleft":-1}')
            ->push('{"result":true,"tokens":"13","p_tokens":"0","timeleft":86397}'),
        'sigil.outwar.com/ajax/challenge_claim.php*' => Http::response('{"result":true,"message":"You have claimed 3 challenge tokens!"}'),
    ]);

    $this->postJson("/api/v1/rgas/{$this->rga->id}/challenge-tokens/claim")
        ->assertOk()
        ->assertJsonPath('message', 'You have claimed 3 challenge tokens!')
        ->assertJsonPath('data.tokens', 13);
});

it('passes on the game\'s refusal at the cap', function () {
    Http::fake([
        'sigil.outwar.com/ajax/challenge_status.php*' => Http::response(gameFixture('challenge/challenge_status.json')),
        'sigil.outwar.com/ajax/challenge_claim.php*' => Http::response(gameFixture('challenge/challenge_claim_refused_max.json')),
    ]);

    $this->postJson("/api/v1/rgas/{$this->rga->id}/challenge-tokens/claim")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You have reached your max of 30 challenge tokens!')
        ->assertJsonPath('data.tokens', 30);
});

it('does not ask the game to claim before the daily timer is up', function () {
    Http::fake(['sigil.outwar.com/ajax/challenge_status.php*' => Http::response('{"result":true,"tokens":"3","p_tokens":"0","timeleft":3600}')]);

    $this->postJson("/api/v1/rgas/{$this->rga->id}/challenge-tokens/claim")
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'already claimed'));

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'challenge_claim.php')))->toBeEmpty();
});

it('needs a live session', function () {
    $this->rga->update(['cookies' => null]);
    Http::fake();

    $this->getJson("/api/v1/rgas/{$this->rga->id}/challenge-tokens")->assertUnprocessable();
    $this->postJson("/api/v1/rgas/{$this->rga->id}/challenge-tokens/claim")->assertUnprocessable();

    Http::assertNothingSent();
});

it('is off limits for another user\'s RGA', function () {
    $foreign = Rga::factory()->for(User::factory())->withSession()->create();

    $this->getJson("/api/v1/rgas/{$foreign->id}/challenge-tokens")->assertForbidden();
    $this->postJson("/api/v1/rgas/{$foreign->id}/challenge-tokens/claim")->assertForbidden();
});

it('switches auto-claim per RGA', function () {
    $this->patchJson("/api/v1/rgas/{$this->rga->id}", ['auto_claim_tokens' => false])
        ->assertOk()
        ->assertJsonPath('data.auto_claim_tokens', false);

    expect($this->rga->fresh()->auto_claim_tokens)->toBeFalse();
});
