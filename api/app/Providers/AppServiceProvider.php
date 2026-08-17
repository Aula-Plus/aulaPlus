<?php

namespace App\Providers;

use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A queue worker is a long-running process, so the Tenancy static
        // override survives from one job to the next. Reset it around every job:
        // each job must establish its own tenant (via Tenancy::forSchool()), and
        // one that forgets can never silently inherit the previous job's school.
        Queue::before(fn () => Tenancy::forget());
        Queue::after(fn () => Tenancy::forget());

        // Global fallback rate limit for the whole API (wired to the "api"
        // middleware group via ->throttleApi() in bootstrap/app.php). Stricter
        // endpoint-specific limits (e.g. /login's throttle:6,1) still apply on
        // top of this, since middleware stacks.
        RateLimiter::for('api', fn ($request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
