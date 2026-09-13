<?php

use App\Http\Controllers\AccommodationApprovalController;
use App\Http\Controllers\AccommodationController;
use App\Http\Controllers\AccommodationInstanceOverrideController;
use App\Http\Controllers\AdoptionDashboardController;
use App\Http\Controllers\AIProposalController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentResultController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\BarrierAccommodationController;
use App\Http\Controllers\GroupCommentController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupProfileController;
use App\Http\Controllers\GroupTrackingController;
use App\Http\Controllers\ScheduledFollowUpController;
use App\Http\Controllers\ScreeningTestApplicationController;
use App\Http\Controllers\ScreeningTestDesignApprovalController;
use App\Http\Controllers\ScreeningTestDesignController;
use App\Http\Controllers\ScreeningTestResultController;
use App\Http\Controllers\ScreeningTestTypeController;
use App\Http\Controllers\StudentCommentController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentHistoryController;
use App\Http\Controllers\StudentPerformanceTimelineController;
use App\Http\Controllers\StudentResultController;
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

        // Session 7: scheduled follow-ups (docs/prompts/17-seguimiento-
        // programado.md). A person schedules a follow-up on a student for a
        // date; it is scheduled by a person and never expires on its own.
        // `is_overdue` is computed server-side by the resource.
        Route::get('/students/{student}/scheduled-follow-ups', [ScheduledFollowUpController::class, 'index']);
        Route::post('/students/{student}/scheduled-follow-ups', [ScheduledFollowUpController::class, 'store']);
        Route::post('/scheduled-follow-ups/{followUp}/resolve', [ScheduledFollowUpController::class, 'resolve']);

        // Session 11: "Perfil de grupo" aggregators (docs/prompts/21-perfil-de-
        // grupo.md). All read-only and strictly aggregate — accommodations
        // summarized by type (never a roster), a group performance line with
        // count-by-(type,date) marks (never a student identifier), and the
        // group's scheduled follow-ups filtered per-student by policy.
        Route::get('/groups/{group}/accommodations-summary', [GroupProfileController::class, 'accommodationsSummary']);
        Route::get('/groups/{group}/performance-timeline', [GroupProfileController::class, 'performanceTimeline']);
        Route::get('/groups/{group}/scheduled-follow-ups', [ScheduledFollowUpController::class, 'indexForGroup']);

        // Session 5: AI teaching assistant (docs/prompts/05-asistente-ia-
        // docente.md). The assistant proposes drafts; the teacher applies or
        // discards. Only generation is throttled (cost control, per school).
        Route::post('/groups/{group}/assistant/generate', [AIProposalController::class, 'generate'])
            ->middleware('throttle:ai-proposal-generate');
        Route::get('/ai-proposals/{ai_proposal}', [AIProposalController::class, 'show']);
        Route::post('/ai-proposals/{ai_proposal}/apply', [AIProposalController::class, 'apply']);
        Route::post('/ai-proposals/{ai_proposal}/discard', [AIProposalController::class, 'discard']);

        // Session 6: assessments CRUD + per-student results (docs/prompts/
        // 13-evaluaciones-resultados.md). Fills the gap left by Session 1
        // (Assessment had a model/policy but no endpoints) and adds the
        // AssessmentResult entity that Perfil de alumno's chart consumes.
        Route::get('/groups/{group}/assessments', [AssessmentController::class, 'index']);
        Route::post('/groups/{group}/assessments', [AssessmentController::class, 'store']);
        Route::patch('/assessments/{assessment}', [AssessmentController::class, 'update']);
        Route::delete('/assessments/{assessment}', [AssessmentController::class, 'destroy']);

        Route::get('/assessments/{assessment}/results', [AssessmentResultController::class, 'index']);
        Route::post('/assessments/{assessment}/results', [AssessmentResultController::class, 'store']);
        Route::get('/students/{student}/results', [StudentResultController::class, 'index']);

        // Session 13: screening tests ("pruebas de sondeo", docs/prompts/
        // 11-pruebas-de-sondeo.md). School-wide, psychopedagogy-led module;
        // teachers see nothing here. Catalog + versioned design with the same
        // draft/approval pattern as Accommodation, then application to a group
        // with anonymous per-application codes and score-driven color bands.
        Route::get('/screening-test-types', [ScreeningTestTypeController::class, 'index']);
        Route::post('/screening-test-types', [ScreeningTestTypeController::class, 'store']);
        Route::post('/screening-test-types/{type}/designs', [ScreeningTestDesignController::class, 'store']);
        Route::post('/screening-test-designs/{design}/approve', [ScreeningTestDesignApprovalController::class, 'approve']);
        Route::post('/screening-test-designs/{design}/reject', [ScreeningTestDesignApprovalController::class, 'reject']);

        Route::get('/groups/{group}/screening-test-applications', [ScreeningTestApplicationController::class, 'index']);
        Route::post('/groups/{group}/screening-test-applications', [ScreeningTestApplicationController::class, 'store']);
        Route::get('/screening-test-applications/{application}/roster', [ScreeningTestApplicationController::class, 'roster']);
        Route::get('/screening-test-applications/{application}/results', [ScreeningTestApplicationController::class, 'results']);

        Route::patch('/screening-test-results/{result}', [ScreeningTestResultController::class, 'update']);

        // Session 10: performance timeline (docs/prompts/20-linea-tiempo-
        // alumno.md). Read-only aggregator over the results line + six mark
        // sources (Sessions 3/6/8/9 + Barrier/CalendarEvent); same auth as the
        // tracking view, with clinical mark types gated per-user in the builder.
        Route::get('/students/{student}/performance-timeline', [StudentPerformanceTimelineController::class, 'show']);

        // Session 8: accommodation category + per-instance deactivation
        // (docs/prompts/18-ajustes-categoria-instancia.md). Adds the missing
        // Accommodation create/edit endpoints (where `category` is enforced)
        // and the AccommodationInstanceOverride entity — deactivating an
        // accommodation for one assessment, with a mandatory reason.
        Route::post('/students/{student}/accommodations', [AccommodationController::class, 'store']);
        Route::patch('/accommodations/{accommodation}', [AccommodationController::class, 'update']);

        Route::post('/accommodations/{accommodation}/instance-overrides', [AccommodationInstanceOverrideController::class, 'store']);
        Route::get('/assessments/{assessment}/instance-overrides', [AccommodationInstanceOverrideController::class, 'index']);
    });
});
