<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CurrentUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by bootstrap/app.php inside the "api" group, which
| runs Sanctum's stateful middleware (session + CSRF for first-party SPA
| requests). The frontend must fetch GET /sanctum/csrf-cookie before any
| state-changing request so the XSRF-TOKEN cookie is present.
|
*/

// Public authentication endpoints (session is established here for the SPA).
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('login');

// Authenticated endpoints. Every route below requires a valid first-party
// session (or bearer token) resolved by the "sanctum" guard.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/me', CurrentUserController::class)->name('me');
});
