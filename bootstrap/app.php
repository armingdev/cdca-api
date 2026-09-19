<?php

use App\Game\Exceptions\GameException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Anything the game itself refused is a 422 for the client: the request
        // was well-formed, the game just said no. Mapped once here so controllers
        // don't each repeat the same try/catch.
        $exceptions->render(
            fn (GameException $exception) => response()->json(['message' => $exception->getMessage()], 422),
        );

        // Production reports Eloquent strict-mode violations instead of
        // throwing them (see AppServiceProvider). One bad access inside an
        // engine loop repeats thousands of times an hour, so each distinct
        // violation is logged a few times a minute rather than every time.
        $exceptions->throttle(function (Throwable $exception): ?Limit {
            $isStrictViolation = $exception instanceof LazyLoadingViolationException
                || $exception instanceof MissingAttributeException
                || $exception instanceof MassAssignmentException;

            return $isStrictViolation ? Limit::perMinute(5)->by($exception->getMessage()) : null;
        });
    })->create();
