<?php

use App\Jobs\ClaimChallengeTokensJob;
use App\Models\Character;
use App\Models\Rga;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Fake the two challenge endpoints on Torax. The characters below live on
 * Torax only, so a request that went to the RGA-level host (no character
 * selected — the game answers "must be logged in") would be a stray request.
 *
 * @param  list<string>  $statuses  successive challenge_status.php bodies
 */
function fakeChallengeEndpoints(array $statuses, string $claim = '{"result":true,"message":"You have claimed 3 challenge tokens!"}'): void
{
    $sequence = Http::sequence();

    foreach ($statuses as $status) {
        $sequence->push($status);
    }

    Http::fake([
        'torax.outwar.com/ajax/challenge_status.php*' => $sequence,
        'torax.outwar.com/ajax/challenge_claim.php*' => Http::response($claim),
    ]);
}

function claimRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'challenge_claim.php'))->count();
}

beforeEach(function () {
    $this->rga = Rga::factory()->withSession()->create();
    Character::factory()->for($this->rga)->torax()->create();
});

it('claims when the daily timer is up and there is room, then stores the new reading', function () {
    $this->freezeSecond();
    fakeChallengeEndpoints([
        '{"result":true,"tokens":"27","p_tokens":"5","timeleft":-120}',
        '{"result":true,"tokens":"30","p_tokens":"5","timeleft":86397}',
    ]);

    ClaimChallengeTokensJob::dispatchSync($this->rga);

    $rga = $this->rga->fresh();
    expect(claimRequests())->toBe(1)
        ->and($rga->challenge_tokens)->toBe(30)
        ->and($rga->challenge_tokens_purchased)->toBe(5)
        ->and($rga->challenge_claimed_at->equalTo(now()))->toBeTrue()
        ->and($rga->challenge_claim_at->equalTo(now()->addSeconds(86397)))->toBeTrue();
});

it('does not claim at the cap and re-checks within the hour', function () {
    $this->freezeSecond();
    fakeChallengeEndpoints([gameFixture('challenge/challenge_status.json')]);

    ClaimChallengeTokensJob::dispatchSync($this->rga);

    $rga = $this->rga->fresh();
    expect(claimRequests())->toBe(0)
        ->and($rga->challenge_tokens)->toBe(30)
        ->and($rga->challenge_claim_at->equalTo(now()->addHour()))->toBeTrue()
        ->and($rga->challenge_claimed_at)->toBeNull();
});

it('waits for the daily timer', function () {
    $this->freezeSecond();
    fakeChallengeEndpoints(['{"result":true,"tokens":"3","p_tokens":"0","timeleft":3600}']);

    ClaimChallengeTokensJob::dispatchSync($this->rga);

    expect(claimRequests())->toBe(0)
        ->and($this->rga->fresh()->challenge_claim_at->equalTo(now()->addSeconds(3600)))->toBeTrue();
});

it('leaves an RGA alone when auto-claim is off or it has no session', function (Closure $state) {
    $state($this->rga);
    Http::fake();

    ClaimChallengeTokensJob::dispatchSync($this->rga);

    Http::assertNothingSent();
})->with([
    'auto-claim off' => [fn (Rga $rga) => $rga->update(['auto_claim_tokens' => false])],
    'no session' => [fn (Rga $rga) => $rga->update(['cookies' => null])],
    'session booted' => [fn (Rga $rga) => $rga->update(['status' => Rga::STATUS_INVALID])],
]);

it('backs off when the RGA has no character of its own to select', function () {
    $this->freezeSecond();
    $rga = Rga::factory()->withSession()->create();
    Character::factory()->for($rga)->trustee()->create();
    Http::fake();

    ClaimChallengeTokensJob::dispatchSync($rga);

    Http::assertNothingSent();
    expect($rga->fresh()->challenge_claim_at->equalTo(now()->addHours(6)))->toBeTrue();
});

it('swallows a page it cannot read and tries again in an hour', function () {
    $this->freezeSecond();
    fakeChallengeEndpoints(['<html>maintenance</html>']);

    ClaimChallengeTokensJob::dispatchSync($this->rga);

    expect($this->rga->fresh()->challenge_claim_at->equalTo(now()->addHour()))->toBeTrue();
});

it('queues a check only for RGAs that are due, opted in and connected', function () {
    Queue::fake();
    $due = $this->rga;
    $dueAgain = Rga::factory()->withSession()->create(['challenge_claim_at' => now()->subMinute()]);
    Rga::factory()->withSession()->create(['challenge_claim_at' => now()->addHour()]);
    Rga::factory()->withSession()->create(['auto_claim_tokens' => false]);
    Rga::factory()->create();
    Rga::factory()->withSession()->invalid()->create();

    $this->artisan('outwar:challenge-tokens-claim')->assertSuccessful();

    Queue::assertPushed(ClaimChallengeTokensJob::class, 2);
    Queue::assertPushed(ClaimChallengeTokensJob::class, fn (ClaimChallengeTokensJob $job) => $job->rga->is($due));
    Queue::assertPushed(ClaimChallengeTokensJob::class, fn (ClaimChallengeTokensJob $job) => $job->rga->is($dueAgain));
});
