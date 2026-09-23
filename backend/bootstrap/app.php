<?php

use App\Http\Middleware\EnsureUserHasRole;
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
    ->withMiddleware(function (Middleware $middleware) {
        // Enable Sanctum's stateful API middleware so the SPA can
        // authenticate via first-party cookies during local/dev use,
        // while still allowing token-based auth for other clients.
        $middleware->statefulApi();

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Laravel's ApplicationBuilder::withMiddleware() always seeds a
        // default guest-redirect callback of `fn () => route('login')`
        // before this closure runs (see vendor/laravel/framework/src/
        // Illuminate/Foundation/Configuration/ApplicationBuilder.php).
        // This app has no named `login` route anywhere - it's API-only -
        // so on any unauthenticated request that doesn't send an
        // `Accept: application/json` header, that default closure was
        // throwing RouteNotFoundException instead of the intended 401
        // (Authenticate::unauthenticated() only skips calling it when
        // $request->expectsJson() is already true). Overriding it here
        // to never redirect for API requests fixes that without a route
        // that doesn't otherwise exist in this app, and without touching
        // `shouldRenderJsonWhen` below, which still decides how the
        // resulting 401 is rendered.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
