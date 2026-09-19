<?php

use App\Models\Character;
use App\Models\Rga;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Exceptions;

/**
 * Boot the provider the way production does. The violation callbacks are
 * static on Model, so afterEach() clears them before the next test runs.
 */
function bootProviderAsProduction(): void
{
    app()->detectEnvironment(fn (): string => 'production');

    new AppServiceProvider(app())->boot();
}

afterEach(function () {
    Model::handleLazyLoadingViolationUsing(null);
    Model::handleMissingAttributeViolationUsing(null);
    Model::handleDiscardedAttributeViolationUsing(null);
});

it('throws on a missing attribute outside production', function () {
    $character = Character::factory()->for(Rga::factory())->create();

    $partial = Character::query()->select('id')->findOrFail($character->id);

    expect(fn () => $partial->name)->toThrow(MissingAttributeException::class);
});

it('reads a missing attribute as null in production and reports it', function () {
    Exceptions::fake();
    $character = Character::factory()->for(Rga::factory())->create();
    bootProviderAsProduction();

    $partial = Character::query()->select('id')->findOrFail($character->id);

    expect($partial->name)->toBeNull();

    Exceptions::assertReported(MissingAttributeException::class);
});

it('still lazy loads in production and reports the n+1', function () {
    Exceptions::fake();
    $rga = Rga::factory()->create();
    Character::factory()->for($rga)->count(2)->create();
    bootProviderAsProduction();

    // The guard is only stamped on models hydrated from a multi-row result.
    $loaded = Character::query()->get()->first();

    expect($loaded->rga->is($rga))->toBeTrue();

    Exceptions::assertReported(LazyLoadingViolationException::class);
});

it('does not report a lazy load on a just-created model in production', function () {
    Exceptions::fake();
    bootProviderAsProduction();

    $character = Character::factory()->for(Rga::factory())->create();
    $character->preventsLazyLoading = true;

    expect($character->rga)->not->toBeNull();

    Exceptions::assertNothingReported();
});

it('drops an unfillable key in production and reports it', function () {
    Exceptions::fake();
    bootProviderAsProduction();

    $character = new Character()->fill(['name' => 'Kix', 'not_a_column' => 1]);

    expect($character->getAttributes())->toBe(['name' => 'Kix']);

    Exceptions::assertReported(MassAssignmentException::class);
});
