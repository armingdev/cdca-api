<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('grants Horizon access only to authorized emails', function () {
    config(['horizon.authorized_emails' => ['owner@example.com']]);

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $stranger = User::factory()->create(['email' => 'stranger@example.com']);

    expect(Gate::forUser($owner)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('locks the Horizon dashboard down when no emails are authorized', function () {
    config(['horizon.authorized_emails' => []]);

    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});
