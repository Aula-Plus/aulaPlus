<?php

use App\Http\Controllers\AccommodationController;
use App\Http\Controllers\AccommodationInstanceOverrideController;
use App\Http\Controllers\AccommodationProposalController;
use App\Http\Controllers\AccommodationReviewController;
use App\Http\Controllers\AdoptionDashboardController;
use App\Http\Controllers\AIProposalController;
use App\Http\Controllers\AiReportSettingsController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertOutcomeController;
use App\Http\Controllers\AlertSettingsController;
use App\Http\Controllers\AlertThreadController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentResultController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\BarrierAccommodationController;
use App\Http\Controllers\CommentCategoryController;
use App\Http\Controllers\CommentTrendSettingsController;
use App\Http\Controllers\FollowUpMemberController;
use App\Http\Controllers\FollowUpStageController;
use App\Http\Controllers\GradesVisibilitySettingsController;
use App\Http\Controllers\GroupAccommodationNarrativeController;
use App\Http\Controllers\GroupCommentController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupProfileController;
use App\Http\Controllers\GroupTeacherAssignmentController;
use App\Http\Controllers\GroupTrackingController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportDocumentController;
use App\Http\Controllers\ScheduledFollowUpController;
use App\Http\Controllers\ScreeningTestApplicationController;
use App\Http\Controllers\ScreeningTestDesignController;
use App\Http\Controllers\ScreeningTestResultController;
use App\Http\Controllers\ScreeningTestTypeController;
use App\Http\Controllers\StudentCommentController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentHistoryController;
use App\Http\Controllers\StudentPerformanceTimelineController;
use App\Http\Controllers\StudentResultController;
use App\Http\Controllers\StudentTeamSummaryController;
use App\Http\Controllers\StudentTrackingController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SubjectSyllabusController;
use App\Http\Controllers\SupportMessageController;
use App\Http\Controllers\TeacherOptionsController;
use App\Http\Controllers\UsageJourneyStepController;
use App\Http\Controllers\UserController;
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
    ->middleware('throttle:login')
    ->name('login');

// Public, token-gated invitation acceptance (no session yet). The token is the
// authorization gate; both routes are rate-limited.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:10,1');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
    ->middleware('throttle:10,1');

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
        // Direction proposes accommodations, psychopedagogy confirms them
        // (ClickUp 86e3qkwkk). The external-approval approve/reject routes
        // were removed in the same task («Requiere aprobación externa» is no
        // longer part of the API).
        Route::post('/accommodations/{accommodation}/proposal/confirm', [AccommodationReviewController::class, 'confirm']);
        Route::post('/accommodations/{accommodation}/proposal/return', [AccommodationReviewController::class, 'returnProposal']);
        Route::post('/accommodations/{accommodation}/proposal/resubmit', [AccommodationReviewController::class, 'resubmit']);
        Route::post('/accommodations/{accommodation}/proposal/withdraw', [AccommodationReviewController::class, 'withdraw']);
        Route::post('/accommodations/{accommodation}/proposal/reject', [AccommodationReviewController::class, 'reject']);
        Route::get('/students/{student}/accommodation-proposals', [AccommodationReviewController::class, 'index']);
        Route::get('/accommodations/{accommodation}/proposal/history', [AccommodationReviewController::class, 'history']);

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
        Route::get('/grades-visibility', [GradesVisibilitySettingsController::class, 'show']);
        Route::put('/grades-visibility', [GradesVisibilitySettingsController::class, 'update']);
        Route::put('/students/{student}/team-summary', [StudentTeamSummaryController::class, 'update']);

        // Student reports (ClickUp 86e3jujgx): upload, anonymized-text review,
        // AI proposals reviewed one by one, and direction's school switch.
        Route::get('/students/{student}/reports', [ReportDocumentController::class, 'index']);
        Route::post('/students/{student}/reports', [ReportDocumentController::class, 'store']);
        Route::get('/reports/{report}', [ReportDocumentController::class, 'show']);
        Route::get('/reports/{report}/download', [ReportDocumentController::class, 'download']);
        Route::patch('/reports/{report}', [ReportDocumentController::class, 'update']);
        Route::delete('/reports/{report}', [ReportDocumentController::class, 'destroy']);
        Route::put('/reports/{report}/anonymized-text', [ReportDocumentController::class, 'updateText']);
        // Each confirmation is a paid AI call (cost control, like the other AI endpoints).
        Route::post('/reports/{report}/confirm-send', [ReportDocumentController::class, 'confirmSend'])
            ->middleware('throttle:5,1');
        Route::post('/reports/{report}/proposals', [AccommodationProposalController::class, 'store']);
        Route::post('/accommodation-proposals/{proposal}/review', [AccommodationProposalController::class, 'review']);
        Route::get('/ai-report-setting', [AiReportSettingsController::class, 'show']);
        Route::put('/ai-report-setting', [AiReportSettingsController::class, 'update']);
        Route::get('/groups/{group}/tracking', [GroupTrackingController::class, 'show']);

        Route::get('/students/{student}/alerts', [AlertController::class, 'forStudent']);
        Route::get('/groups/{group}/alerts', [AlertController::class, 'forGroup']);
        Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve']);

        // Sustained-low-performance alerts (ClickUp 86e3jpzcv): the open alerts
        // that reach the current user, and the school's alert settings
        // (conditions + who each type reaches first) for direction and
        // psychopedagogy.
        Route::get('/alerts', [AlertController::class, 'index']);
        Route::get('/alert-settings', [AlertSettingsController::class, 'show']);
        Route::post('/alert-rules', [AlertSettingsController::class, 'storeRule']);
        Route::patch('/alert-rules/{alertRule}', [AlertSettingsController::class, 'updateRule']);
        Route::delete('/alert-rules/{alertRule}', [AlertSettingsController::class, 'destroyRule']);
        Route::put('/alert-routing/{type}', [AlertSettingsController::class, 'updateRouting']);

        // The three ways out of an alert (ClickUp 86e3jpzdp): me ocupo yo / se
        // la paso a otro rol / la dejo en observación, each with responsible
        // and deadline; plus who it can be handed to.
        Route::post('/alerts/{alert}/outcome', [AlertOutcomeController::class, 'store']);
        Route::get('/alerts/{alert}/handoff-candidates', [AlertOutcomeController::class, 'candidates']);
        // Notes in an alert's thread, by whoever has it (ClickUp 86e3jpzdp
        // follow-up). Working a case with several people happens on a
        // follow-up linked to the alert, not on the alert itself.
        Route::post('/alerts/{alert}/notes', [AlertThreadController::class, 'note']);

        // Comment categories (replace the old comment "tone"): a school-
        // editable catalog (director writes) plus the school's recurrence
        // threshold for the trend mark (psychopedagogy/direction read,
        // director writes).
        Route::get('/comment-categories', [CommentCategoryController::class, 'index']);
        Route::post('/comment-categories', [CommentCategoryController::class, 'store']);
        Route::put('/comment-categories/{category}', [CommentCategoryController::class, 'update']);
        Route::delete('/comment-categories/{category}', [CommentCategoryController::class, 'destroy']);
        Route::get('/comment-trend-settings', [CommentTrendSettingsController::class, 'show']);
        Route::put('/comment-trend-settings', [CommentTrendSettingsController::class, 'update']);

        Route::post('/support-messages', [SupportMessageController::class, 'store'])->middleware('throttle:10,1');

        Route::get('/schools/{school}/adoption-dashboard', [AdoptionDashboardController::class, 'show']);
        Route::get('/schools/{school}/adoption-dashboard/teachers', [AdoptionDashboardController::class, 'teachers']);
        Route::get('/schools/{school}/adoption-dashboard/teachers/{teacher}/usage', [AdoptionDashboardController::class, 'teacherUsage']);
        // Creation journeys (ClickUp 86e3qkxtc): the browser reports the steps
        // only it observes; Dirección reads each teacher's journeys.
        Route::get('/schools/{school}/adoption-dashboard/teachers/{teacher}/journeys', [AdoptionDashboardController::class, 'teacherJourneys']);
        Route::post('/usage/journey-steps', [UsageJourneyStepController::class, 'store'])
            ->middleware('throttle:usage-journey-steps');

        // Session 7: scheduled follow-ups (docs/prompts/17-seguimiento-
        // programado.md). A person schedules a follow-up on a student for a
        // date; it is scheduled by a person and never expires on its own.
        // `is_overdue` is computed server-side by the resource.
        Route::get('/students/{student}/scheduled-follow-ups', [ScheduledFollowUpController::class, 'index']);
        Route::post('/students/{student}/scheduled-follow-ups', [ScheduledFollowUpController::class, 'store']);
        Route::get('/students/{student}/follow-up-candidates', [ScheduledFollowUpController::class, 'candidates']);
        Route::get('/scheduled-follow-ups/mine', [ScheduledFollowUpController::class, 'mine']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereUuid('notification');
        Route::post('/scheduled-follow-ups/{followUp}/resolve', [ScheduledFollowUpController::class, 'resolve']);

        // Follow-ups with stages and several people (ClickUp 86e3qkwna),
        // managed from "Mis seguimientos" or the student file alike.
        Route::get('/scheduled-follow-ups/{followUp}', [ScheduledFollowUpController::class, 'show'])->whereNumber('followUp');
        Route::post('/scheduled-follow-ups/{followUp}/stages', [FollowUpStageController::class, 'store']);
        Route::patch('/follow-up-stages/{stage}', [FollowUpStageController::class, 'update']);
        Route::post('/follow-up-stages/{stage}/split', [FollowUpStageController::class, 'split']);
        Route::post('/follow-up-stages/{stage}/close', [FollowUpStageController::class, 'close']);
        Route::post('/follow-up-stages/{stage}/notes', [FollowUpStageController::class, 'storeNote']);
        Route::post('/scheduled-follow-ups/{followUp}/participants', [FollowUpMemberController::class, 'storeParticipant'])
            ->name('follow-ups.participants.store');
        Route::post('/scheduled-follow-ups/{followUp}/participants/{user}/remove', [FollowUpMemberController::class, 'removeParticipant']);
        Route::post('/scheduled-follow-ups/{followUp}/general-responsibles', [FollowUpMemberController::class, 'storeGeneralResponsible'])
            ->name('follow-ups.general-responsibles.store');
        Route::post('/scheduled-follow-ups/{followUp}/general-responsibles/{user}/remove', [FollowUpMemberController::class, 'removeGeneralResponsible']);
        Route::post('/scheduled-follow-ups/{followUp}/withdraw', [FollowUpMemberController::class, 'withdraw']);
        Route::post('/scheduled-follow-ups/{followUp}/hide', [FollowUpMemberController::class, 'hide']);

        // Session 11: "Perfil de grupo" aggregators (docs/prompts/21-perfil-de-
        // grupo.md). All read-only and strictly aggregate — accommodations
        // summarized by type (never a roster), a group performance line with
        // count-by-(type,date) marks (never a student identifier), and the
        // group's scheduled follow-ups filtered per-student by policy.
        Route::get('/groups/{group}/accommodations-summary', [GroupProfileController::class, 'accommodationsSummary']);
        Route::get('/groups/{group}/accommodation-narrative', [GroupAccommodationNarrativeController::class, 'show']);
        Route::post('/groups/{group}/accommodation-narrative/generate', [GroupAccommodationNarrativeController::class, 'generate'])
            ->middleware('throttle:5,1');
        Route::post('/groups/{group}/accommodation-narrative/{narrative}/publish', [GroupAccommodationNarrativeController::class, 'publish']);
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
        // teachers see nothing here. Catalog + versioned design (a saved design
        // rules at once and Dirección can revert it, screen 08), then
        // application to a group with anonymous per-application codes and
        // score-driven bands.
        Route::get('/screening-test-types', [ScreeningTestTypeController::class, 'index']);
        Route::post('/screening-test-types', [ScreeningTestTypeController::class, 'store']);
        Route::patch('/screening-test-types/{type}', [ScreeningTestTypeController::class, 'update']);
        Route::get('/screening-test-types/{type}/designs', [ScreeningTestDesignController::class, 'index']);
        Route::post('/screening-test-types/{type}/designs', [ScreeningTestDesignController::class, 'store']);
        Route::post('/screening-test-types/{type}/impact', [ScreeningTestDesignController::class, 'impact']);
        Route::post('/screening-test-designs/{design}/revert', [ScreeningTestDesignController::class, 'revert']);

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

        // Subjects ("materias") catalog — director-managed, read by all staff.
        Route::apiResource('subjects', SubjectController::class);

        // A subject's curricular program PDF (ClickUp 86e3dt6ag): private
        // file, temporary URL only. Direction manages it; direction,
        // psychopedagogy and the subject's teachers can open it.
        Route::post('/subjects/{subject}/syllabus', [SubjectSyllabusController::class, 'store']);
        Route::get('/subjects/{subject}/syllabus', [SubjectSyllabusController::class, 'show']);
        Route::delete('/subjects/{subject}/syllabus', [SubjectSyllabusController::class, 'destroy']);

        // Director-managed teacher-subject assignments per group.
        Route::get('/groups/{group}/teacher-assignments', [GroupTeacherAssignmentController::class, 'index']);
        Route::post('/groups/{group}/teacher-assignments', [GroupTeacherAssignmentController::class, 'store']);
        Route::delete('/groups/{group}/teacher-assignments', [GroupTeacherAssignmentController::class, 'destroy']);

        // Session: user management (docs/superpowers/specs/2026-09-15-user-
        // management-design.md). Director-only staff onboarding + lifecycle.
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/disable', [UserController::class, 'disable']);
        Route::post('/users/{user}/enable', [UserController::class, 'enable']);
        Route::post('/users/{user}/resend-invitation', [UserController::class, 'resendInvitation']);
    });
});
