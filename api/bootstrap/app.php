<?php

use Illuminate\Console\Scheduling\Schedule;
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
    ->withSchedule(function (Schedule $schedule): void {
        // Early-alert generation (docs/prompts/04-seguimiento-institucional.md
        // §4) — daily is enough for a v1 threshold rule over a 15-day window.
        $schedule->command('alerts:generate')->daily();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable Sanctum SPA (cookie-based) authentication for the API group.
        // Requests coming from configured stateful domains receive session +
        // CSRF protection; everything else falls back to bearer-token auth.
        $middleware->statefulApi();

        // Global rate limit for every /api/* route (limiter defined in
        // AppServiceProvider). Individual endpoints may still layer a
        // stricter throttle on top (e.g. /login's throttle:6,1).
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
