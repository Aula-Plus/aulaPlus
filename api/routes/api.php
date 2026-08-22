<?php

use App\Http\Controllers\AccommodationApprovalController;
use App\Http\Controllers\AdoptionDashboardController;
use App\Http\Controllers\AIProposalController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\BarrierAccommodationController;
use App\Http\Controllers\GroupCommentController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupTrackingController;
use App\Http\Controllers\StudentCommentController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentHistoryController;
use App\Http\Controllers\StudentTrackingController;
use App\Http\Controllers\TeacherOptionsController;
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
    Route::get('/teachers', TeacherOptionsController::class);
    Route::apiResource('groups', GroupController::class);
    Route::apiResource('students', StudentController::class);

    // Versioned routes start here (session 3): approval/validation flows and
    // audit-log-backed traceability. Existing unversioned routes above are
    // untouched.
    Route::prefix('v1')->group(function (): void {
        Route::post('/accommodations/{accommodation}/approve', [AccommodationApprovalController::class, 'approve']);
        Route::post('/accommodations/{accommodation}/reject', [AccommodationApprovalController::class, 'reject']);

        Route::get('/barriers/{barrier}/accommodations', [BarrierAccommodationController::class, 'index']);
        Route::post('/barriers/{barrier}/accommodations', [BarrierAccommodationController::class, 'store']);
        Route::post('/barriers/{barrier}/accommodations/{accommodation}/validate', [BarrierAccommodationController::class, 'validateLink']);

        Route::get('/students/{student}/history', [StudentHistoryController::class, 'show']);

        // Session 4: institutional tracking (docs/prompts/04-seguimiento-
        // institucional.md) — comments, student/group tracking aggregator
        // views, early alerts, and the director-only adoption dashboard.
        Route::get('/students/{student}/comments', [StudentCommentController::class, 'index']);
        Route::post('/students/{student}/comments', [StudentCommentController::class, 'store']);
        Route::get('/groups/{group}/comments', [GroupCommentController::class, 'index']);
        Route::post('/groups/{group}/comments', [GroupCommentController::class, 'store']);

        Route::get('/students/{student}/tracking', [StudentTrackingController::class, 'show']);
        Route::get('/groups/{group}/tracking', [GroupTrackingController::class, 'show']);

        Route::get('/students/{student}/alerts', [AlertController::class, 'forStudent']);
        Route::get('/groups/{group}/alerts', [AlertController::class, 'forGroup']);
        Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve']);

        Route::get('/schools/{school}/adoption-dashboard', [AdoptionDashboardController::class, 'show']);

        // Session 5: AI teaching assistant (docs/prompts/05-asistente-ia-
        // docente.md). The assistant proposes drafts; the teacher applies or
        // discards. Only generation is throttled (cost control, per school).
        Route::post('/groups/{group}/assistant/generate', [AIProposalController::class, 'generate'])
            ->middleware('throttle:ai-proposal-generate');
        Route::get('/ai-proposals/{ai_proposal}', [AIProposalController::class, 'show']);
        Route::post('/ai-proposals/{ai_proposal}/apply', [AIProposalController::class, 'apply']);
        Route::post('/ai-proposals/{ai_proposal}/discard', [AIProposalController::class, 'discard']);
    });
});
