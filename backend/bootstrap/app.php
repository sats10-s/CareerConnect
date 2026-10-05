<?php

use App\Http\Middleware\EnsureCompanyApproved;
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
    // The Notifications & Announcements module's listeners (app/Listeners)
    // are registered explicitly, one by one, in
    // App\Providers\AppServiceProvider::boot() - a deliberate choice,
    // documented on that method, since this codebase had no listener
    // registration precedent of any kind before that module. Laravel's
    // event auto-discovery, however, is ON BY DEFAULT even without ever
    // calling withEvents() - it scans app/Listeners and registers any
    // handle()/__invoke() method by its type-hinted event parameter on
    // its own. Left enabled, every one of those explicitly-registered
    // listeners (all of which use the conventional `handle()` method
    // name) would ALSO be auto-discovered, registering it a second time
    // and firing it - and therefore creating its notification - twice
    // per event.
    //
    // NOTE: `discover: []` (an empty array) does NOT disable discovery -
    // per Illuminate\Foundation\Configuration\ApplicationBuilder::withEvents(),
    // a non-empty array only ADDS extra directories to scan
    // (setEventDiscoveryPaths()), and an empty array is a complete no-op;
    // discovery is only turned off by passing the literal boolean `false`
    // (which calls AppEventServiceProvider::disableEventDiscovery()).
    // `discover: []` was tried first and confirmed (via a still-duplicated
    // PPO notification) to leave discovery fully active - `discover: false`
    // is the actual switch. This makes the explicit registration in
    // AppServiceProvider::boot() the single authoritative place a
    // listener is ever wired up, with no silent second path.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware) {
        // Enable Sanctum's stateful API middleware so the SPA can
        // authenticate via first-party cookies during local/dev use,
        // while still allowing token-based auth for other clients.
        $middleware->statefulApi();

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'company.approved' => EnsureCompanyApproved::class,
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
