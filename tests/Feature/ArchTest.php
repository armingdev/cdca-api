<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;

arch('no debugging leftovers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'sleep', 'usleep'])
    ->not->toBeUsed();

arch('game requests go through the throttled client, never a raw env read')
    ->expect('env')
    ->not->toBeUsed();

arch('captured game data is immutable')
    ->expect('App\Game\Data')
    ->toBeFinal()
    ->toBeReadonly();

// Enums are string-backed so they round-trip readably through the database.
// BrawlType is the exception: it mirrors a numeric game parameter.
arch('every game enum is a string-backed enum')
    ->expect('App\Game\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums()
    ->ignoring('App\Game\Enums\BrawlType');

arch('the game layer never reaches up into HTTP')
    ->expect('App\Game')
    ->not->toUse(['App\Http', 'Illuminate\Http\Request']);

arch('models are Eloquent models')
    ->expect('App\Models')
    ->toExtend(Model::class)
    ->ignoring('App\Models\User');

arch('form requests are form requests')
    ->expect('App\Http\Requests')
    ->toExtend(FormRequest::class);

arch('resources are API resources')
    ->expect('App\Http\Resources')
    ->toExtend(JsonResource::class);

arch('policies stay dependency-free decision makers')
    ->expect('App\Policies')
    ->toOnlyUse(['App\Models']);
