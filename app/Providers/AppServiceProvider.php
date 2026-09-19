<?php

namespace App\Providers;

use App\Game\Http\GameTransport;
use App\Game\World\RoomGraph;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One handler per process is the whole point: it owns the kept-alive
        // connections every game request reuses.
        $this->app->singleton(GameTransport::class);

        // Scoped, not singleton: a queue worker flushes scoped instances
        // between jobs, so a run shares one graph across every runner it
        // builds while the next job still starts from the database's truth.
        $this->app->scoped(RoomGraph::class, fn (): RoomGraph => RoomGraph::fromDatabase());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict();

        if ($this->app->isProduction()) {
            $this->reportStrictViolationsInsteadOfThrowing();
        }

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }

    /**
     * Production keeps the forgiving behaviour — a lazy load still loads, a
     * missing attribute still reads as null, an unfillable key is still
     * dropped — because throwing inside a multi-hour run costs far more than
     * the bug does. But each violation is reported, so it stops being
     * invisible. Reports are rate limited per message in bootstrap/app.php:
     * one bad access inside an engine loop would otherwise flood the log.
     */
    private function reportStrictViolationsInsteadOfThrowing(): void
    {
        Model::handleLazyLoadingViolationUsing(
            function (Model $model, string $relation, LazyLoadingViolationException $exception): void {
                // Laravel itself lets fresh and just-created models lazy load.
                if ($model->exists && ! $model->wasRecentlyCreated) {
                    report($exception);
                }
            },
        );

        Model::handleMissingAttributeViolationUsing(
            function (Model $model, string $key, MissingAttributeException $exception): void {
                report($exception);
            },
        );

        Model::handleDiscardedAttributeViolationUsing(
            function (Model $model, array $keys, MassAssignmentException $exception): void {
                report($exception);
            },
        );
    }
}
