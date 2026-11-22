<?php

use App\Enums\CalendarBody;
use App\Http\Controllers\AccommodationController;
use App\Http\Controllers\AccommodationInboxController;
use App\Http\Controllers\AccommodationInstanceOverrideController;
use App\Http\Controllers\AccommodationProposalController;
use App\Http\Controllers\AccommodationReviewController;
use App\Http\Controllers\AdoptionDashboardController;
use App\Http\Controllers\AIInputReviewController;
use App\Http\Controllers\AIProposalController;
use App\Http\Controllers\AiReportSettingsController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AlertOutcomeController;
use App\Http\Controllers\AlertSettingsController;
use App\Http\Controllers\AlertThreadController;
use App\Http\Controllers\AnnualPlanController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentResultController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\BarrierAccommodationController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ClassSessionObjectiveController;
use App\Http\Controllers\ClassSessionScheduleController;
use App\Http\Controllers\CommentCategoryController;
use App\Http\Controllers\CommentShareController;
use App\Http\Controllers\CommentTrendSettingsController;
use App\Http\Controllers\CrossingController;
use App\Http\Controllers\CurricularOfferingController;
use App\Http\Controllers\EducationLevelController;
use App\Http\Controllers\ExternalMeasureController;
use App\Http\Controllers\FollowUpMemberController;
use App\Http\Controllers\FollowUpStageController;
use App\Http\Controllers\GradesVisibilitySettingsController;
use App\Http\Controllers\GroupCommentController;
use App\Http\Controllers\GroupComparisonController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupLevelController;
use App\Http\Controllers\GroupProfileController;
use App\Http\Controllers\GroupTeacherAssignmentController;
use App\Http\Controllers\GroupTrackingController;
use App\Http\Controllers\IndicatorChartController;
use App\Http\Controllers\IndicatorController;
use App\Http\Controllers\IndicatorDataController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MeetingSheetActionController;
use App\Http\Controllers\MeetingSheetController;
use App\Http\Controllers\MeetingSheetTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NumericGradeSettingsController;
use App\Http\Controllers\OfficialScaleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlannerSettingsController;
use App\Http\Controllers\PsychopedagogyMeetingController;
use App\Http\Controllers\ReportDocumentController;
use App\Http\Controllers\ScheduledFollowUpController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\ScreeningTestApplicationController;
use App\Http\Controllers\ScreeningTestDesignController;
use App\Http\Controllers\ScreeningTestResultController;
use App\Http\Controllers\ScreeningTestTypeController;
use App\Http\Controllers\StudentAnnualReviewController;
use App\Http\Controllers\StudentBarrierController;
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
use App\Http\Controllers\TherapeuticCompanionController;
use App\Http\Controllers\TimetableSlotController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UnitObjectiveController;
use App\Http\Controllers\UsageJourneyStepController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPreferencesController;
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
    // Screen 26: a closed year's group structure is read-only too (Eitán
    // 17/11), unless direction enabled loading in that group.
    Route::apiResource('groups', GroupController::class)
        ->middlewareFor(['update', 'destroy'], 'group.writable');
    // Enrolling a student in a group of a closed year (or moving them there)
    // rewrites that year's roster: same gate as the group's structure.
    Route::apiResource('students', StudentController::class)
        ->middlewareFor(['store', 'update'], 'group.writable');

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

        // «Ajustes y PTP» (screen 06, ClickUp 1dfd3mc4bgc): psychopedagogy's
        // inbox of every accommodation of the school, the yearly review, and
        // registering a barrier from «Nuevo ajuste».
        Route::get('/accommodation-inbox', [AccommodationInboxController::class, 'index']);
        Route::get('/annual-reviews', [StudentAnnualReviewController::class, 'index']);
        Route::get('/annual-reviews/pending', [StudentAnnualReviewController::class, 'pending']);
        Route::post('/students/{student}/annual-review', [StudentAnnualReviewController::class, 'store']);
        Route::get('/students/{student}/barriers', [StudentBarrierController::class, 'index']);
        Route::post('/students/{student}/barriers', [StudentBarrierController::class, 'store']);

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
        Route::post('/groups/{group}/comments', [GroupCommentController::class, 'store'])
            ->middleware('group.writable');
        // «Compartir con» (ClickUp 86e3qkwnv): psychopedagogy shares an
        // extract of a student observation; recipients read only the extract.
        Route::get('/students/{student}/comment-shares', [CommentShareController::class, 'index']);
        Route::post('/comments/{comment}/shares', [CommentShareController::class, 'store']);

        Route::get('/students/{student}/tracking', [StudentTrackingController::class, 'show']);
        Route::get('/grades-visibility', [GradesVisibilitySettingsController::class, 'show']);
        Route::put('/grades-visibility', [GradesVisibilitySettingsController::class, 'update']);

        // Screen 24 · «Escalas oficiales» (ClickUp 1dfd3mc4bgg): direction
        // consults the official scales — read-only, no write route on purpose
        // (loaded by the Aula+ team, OfficialScaleSeeder) — and sets the
        // school's Primaria numeric-grade switch and its own equivalence.
        Route::get('/official-scales', [OfficialScaleController::class, 'index']);
        Route::get('/numeric-grade-settings', [NumericGradeSettingsController::class, 'show']);
        Route::put('/numeric-grade-settings', [NumericGradeSettingsController::class, 'update']);
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
        Route::get('/education-levels', [EducationLevelController::class, 'index']);
        Route::put('/groups/{group}/level', [GroupLevelController::class, 'update'])
            ->middleware('group.writable');
        Route::get('/group-level-setting', [GroupLevelController::class, 'showSetting']);
        Route::put('/group-level-setting', [GroupLevelController::class, 'updateSetting']);
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
        // The person's own display preferences (ClickUp 1dfd3mc4zpk).
        Route::get('/me/preferences', [UserPreferencesController::class, 'show']);
        Route::patch('/me/preferences', [UserPreferencesController::class, 'update']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereUuid('notification');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
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

        // Screen 23 › «Oferta curricular» and «Planificadores» (ClickUp
        // 1dfd3mc4bgf): direction configures; the resolved planner of a
        // group-subject is what its units show (screen 13).
        Route::get('/curricular-offerings', [CurricularOfferingController::class, 'index']);
        Route::put('/groups/{group}/subjects/{subject}/curricular-offering', [CurricularOfferingController::class, 'update'])
            ->middleware('group.writable');
        Route::get('/groups/{group}/subjects/{subject}/planner-fields', [CurricularOfferingController::class, 'planner']);
        Route::get('/planner-settings', [PlannerSettingsController::class, 'show']);
        Route::put('/planner-settings/fields/{definition}', [PlannerSettingsController::class, 'updateField']);
        Route::post('/planner-settings/custom-fields', [PlannerSettingsController::class, 'storeCustom']);
        Route::put('/planner-settings/custom-fields/{plannerField}', [PlannerSettingsController::class, 'updateCustom']);
        Route::delete('/planner-settings/custom-fields/{plannerField}', [PlannerSettingsController::class, 'destroyCustom']);

        // Session 11: "Perfil de grupo" aggregators (docs/prompts/21-perfil-de-
        // grupo.md). All read-only and strictly aggregate — accommodations
        // summarized by type (never a roster), a group performance line with
        // count-by-(type,date) marks (never a student identifier), and the
        // group's scheduled follow-ups filtered per-student by policy.
        Route::get('/groups/{group}/accommodations-summary', [GroupProfileController::class, 'accommodationsSummary']);
        // «Ver lo que recibiría la IA» (ClickUp 1dfd3mc2wx2, P03-AJU-03):
        // Psicopedagogía and Dirección read it, Psicopedagogía marks lines
        // (AIInputMarkPolicy).
        Route::get('/groups/{group}/ai-input', [AIInputReviewController::class, 'show']);
        Route::post('/groups/{group}/ai-input/marks', [AIInputReviewController::class, 'store'])->middleware(['throttle:30,1', 'group.writable']);
        Route::post('/ai-input-marks/{mark}/adjustment-ok', [AIInputReviewController::class, 'confirmAdjustment'])->middleware('throttle:30,1');
        // Screen 05 «Comparativa por año de cursada» (Psicopedagogía y Dirección).
        Route::get('/group-comparison/options', [GroupComparisonController::class, 'options']);
        Route::get('/group-comparison', [GroupComparisonController::class, 'show']);
        Route::get('/groups/{group}/performance-timeline', [GroupProfileController::class, 'performanceTimeline']);
        Route::get('/groups/{group}/scheduled-follow-ups', [ScheduledFollowUpController::class, 'indexForGroup']);

        // Session 5: AI teaching assistant (docs/prompts/05-asistente-ia-
        // docente.md). The assistant proposes drafts; the teacher applies or
        // discards. Only generation is throttled (cost control, per school).
        Route::post('/groups/{group}/assistant/generate', [AIProposalController::class, 'generate'])
            ->middleware('group.writable')
            ->middleware('throttle:ai-proposal-generate');
        Route::get('/ai-proposals/{ai_proposal}', [AIProposalController::class, 'show']);
        Route::post('/ai-proposals/{ai_proposal}/apply', [AIProposalController::class, 'apply'])
            ->middleware('group.writable');
        Route::post('/ai-proposals/{ai_proposal}/discard', [AIProposalController::class, 'discard']);

        // Screen 12 — Programa anual (ClickUp 1dfd3mc4bg8). The teacher builds
        // the plan, its units and their objectives; Psicopedagogía and
        // Dirección read any plan (AnnualPlanPolicy / UnitPolicy). Unit status
        // and coverage are derived (AnnualPlanProgress), never written.
        Route::get('/annual-plans', [AnnualPlanController::class, 'index']);
        Route::get('/annual-plans/options', [AnnualPlanController::class, 'options']);
        Route::post('/annual-plans', [AnnualPlanController::class, 'store'])
            ->middleware('group.writable');
        Route::get('/annual-plans/{annualPlan}', [AnnualPlanController::class, 'show'])->whereNumber('annualPlan');
        Route::patch('/annual-plans/{annualPlan}', [AnnualPlanController::class, 'update'])
            ->middleware('group.writable')->whereNumber('annualPlan');
        Route::post('/annual-plans/{annualPlan}/units', [UnitController::class, 'store'])
            ->middleware('group.writable');
        Route::patch('/units/{unit}', [UnitController::class, 'update'])
            ->middleware('group.writable');
        Route::delete('/units/{unit}', [UnitController::class, 'destroy'])
            ->middleware('group.writable');
        Route::post('/units/{unit}/objectives', [UnitObjectiveController::class, 'store'])
            ->middleware('group.writable');
        Route::patch('/unit-objectives/{objective}', [UnitObjectiveController::class, 'update'])
            ->middleware('group.writable');
        Route::delete('/unit-objectives/{objective}', [UnitObjectiveController::class, 'destroy'])
            ->middleware('group.writable');
        // «Lo terminé»: pick a class of the plan's group and subject and
        // record the objective «todo» there. The objective's state still
        // derives only from the class records (P12-COB-02, screen 13 F-7).
        Route::get('/unit-objectives/{objective}/closable-classes', [UnitObjectiveController::class, 'closableClasses'])
            ->whereNumber('objective');
        Route::patch('/class-sessions/{classSession}/objectives/{objective}', [ClassSessionObjectiveController::class, 'update'])
            ->middleware('group.writable')
            ->whereNumber(['classSession', 'objective']);

        // Session 6: assessments CRUD + per-student results (docs/prompts/
        // 13-evaluaciones-resultados.md). Fills the gap left by Session 1
        // (Assessment had a model/policy but no endpoints) and adds the
        // AssessmentResult entity that Perfil de alumno's chart consumes.
        Route::get('/groups/{group}/assessments', [AssessmentController::class, 'index']);
        Route::post('/groups/{group}/assessments', [AssessmentController::class, 'store'])
            ->middleware('group.writable');
        Route::patch('/assessments/{assessment}', [AssessmentController::class, 'update'])
            ->middleware('group.writable');
        Route::delete('/assessments/{assessment}', [AssessmentController::class, 'destroy'])
            ->middleware('group.writable');

        Route::get('/assessments/{assessment}/results', [AssessmentResultController::class, 'index']);
        Route::get('/assessments/{assessment}/result-scale', [AssessmentResultController::class, 'scale']);
        Route::put('/assessments/{assessment}/result-scale', [AssessmentResultController::class, 'chooseScale'])
            ->middleware('group.writable');
        Route::post('/assessments/{assessment}/results', [AssessmentResultController::class, 'store'])
            ->middleware('group.writable');
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
        Route::post('/groups/{group}/screening-test-applications', [ScreeningTestApplicationController::class, 'store'])
            ->middleware('group.writable');
        Route::post('/screening-test-applications/{application}/recalculate', [ScreeningTestApplicationController::class, 'recalculate'])
            ->middleware('group.writable');
        Route::get('/screening-test-applications/{application}/roster', [ScreeningTestApplicationController::class, 'roster']);
        Route::get('/screening-test-applications/{application}/results', [ScreeningTestApplicationController::class, 'results']);

        Route::patch('/screening-test-results/{result}', [ScreeningTestResultController::class, 'update'])
            ->middleware('group.writable');

        // Screen 30 «Indicadores y cruces» (ClickUp 1dfd3mc4bgp, first
        // delivery: its four tabs). Psicopedagogía and Dirección only; the AI
        // takes no part. Previews and the chart never rank students: previews
        // are aggregate, and the chart's names only go with each point.
        Route::get('/indicator-data', [IndicatorDataController::class, 'index']);
        Route::post('/external-measures', [ExternalMeasureController::class, 'store']);
        Route::patch('/external-measures/{externalMeasure}', [ExternalMeasureController::class, 'update']);
        Route::delete('/external-measures/{externalMeasure}', [ExternalMeasureController::class, 'destroy']);
        Route::get('/external-measures/{externalMeasure}/values', [ExternalMeasureController::class, 'values']);
        Route::put('/external-measures/{externalMeasure}/values', [ExternalMeasureController::class, 'storeValues']);
        Route::post('/external-measures/{externalMeasure}/match-students', [ExternalMeasureController::class, 'matchStudents']);
        Route::get('/indicators', [IndicatorController::class, 'index']);
        Route::post('/indicators', [IndicatorController::class, 'store']);
        Route::post('/indicators/preview', [IndicatorController::class, 'preview']);
        Route::patch('/indicators/{indicator}', [IndicatorController::class, 'update'])->whereNumber('indicator');
        Route::delete('/indicators/{indicator}', [IndicatorController::class, 'destroy'])->whereNumber('indicator');
        Route::get('/crossings', [CrossingController::class, 'index']);
        Route::post('/crossings', [CrossingController::class, 'store']);
        Route::post('/crossings/preview', [CrossingController::class, 'preview']);
        Route::patch('/crossings/{crossing}', [CrossingController::class, 'update'])->whereNumber('crossing');
        Route::delete('/crossings/{crossing}', [CrossingController::class, 'destroy'])->whereNumber('crossing');
        Route::get('/indicator-chart', [IndicatorChartController::class, 'show']);

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

        // On an assessment of the group (`assessment_id` in the body).
        Route::post('/accommodations/{accommodation}/instance-overrides', [AccommodationInstanceOverrideController::class, 'store'])
            ->middleware('group.writable');
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
        Route::post('/groups/{group}/teacher-assignments', [GroupTeacherAssignmentController::class, 'store'])
            ->middleware('group.writable');
        Route::delete('/groups/{group}/teacher-assignments', [GroupTeacherAssignmentController::class, 'destroy'])
            ->middleware('group.writable');

        // Calendar (screen 21, ClickUp 1dfd3mc4bgh): each person's dates in
        // time (the Inicio's «Hoy en tu calendario» reads the same feed),
        // Direction's institutional events, the time/room of a class, and the
        // teacher's private «Mis horarios», which only pre-fills them.
        Route::get('/calendar', [CalendarController::class, 'index']);
        Route::post('/calendar-events', [CalendarController::class, 'store']);
        Route::patch('/calendar-events/{calendarEvent}', [CalendarController::class, 'update']);
        Route::delete('/calendar-events/{calendarEvent}', [CalendarController::class, 'destroy']);
        Route::get('/class-sessions/suggested-slot', [ClassSessionScheduleController::class, 'suggestedSlot']);
        Route::patch('/class-sessions/{classSession}', [ClassSessionScheduleController::class, 'update'])
            ->middleware('group.writable');
        Route::get('/timetable-slots', [TimetableSlotController::class, 'index']);
        Route::post('/timetable-slots', [TimetableSlotController::class, 'store']);
        Route::patch('/timetable-slots/{timetableSlot}', [TimetableSlotController::class, 'update']);
        Route::delete('/timetable-slots/{timetableSlot}', [TimetableSlotController::class, 'destroy']);

        // Screen 10 «Espacio de psicopedagogía» (ClickUp 1dfd3mc4bge): the
        // team's agenda, the therapeutic companions and the meeting sheets.
        // No teacher enters (every endpoint 403s for them); direction gets
        // the workload counts, the current companions (no history) and that
        // each sheet existed. The actions agreed in a sheet are tasks of
        // their owner, whatever their role (`meeting-actions`).
        Route::prefix('psychopedagogy')->group(function (): void {
            Route::get('/overview', [PsychopedagogyMeetingController::class, 'overview']);
            Route::get('/staff', [PsychopedagogyMeetingController::class, 'staff']);
            Route::get('/meetings', [PsychopedagogyMeetingController::class, 'index']);
            Route::post('/meetings', [PsychopedagogyMeetingController::class, 'store']);
            Route::put('/meetings/{meeting}', [PsychopedagogyMeetingController::class, 'update'])->whereNumber('meeting');
            Route::post('/meetings/{meeting}/complete', [PsychopedagogyMeetingController::class, 'complete'])->whereNumber('meeting');
            Route::get('/companions', [TherapeuticCompanionController::class, 'index']);
            Route::post('/companions', [TherapeuticCompanionController::class, 'store']);
            Route::patch('/companions/{companion}', [TherapeuticCompanionController::class, 'update'])->whereNumber('companion');
            Route::post('/companions/{companion}/end', [TherapeuticCompanionController::class, 'end'])->whereNumber('companion');
            Route::get('/sheets', [MeetingSheetController::class, 'index']);
            Route::post('/sheets', [MeetingSheetController::class, 'store']);
            Route::get('/sheets/{sheet}', [MeetingSheetController::class, 'show'])->whereNumber('sheet');
            Route::put('/sheets/{sheet}', [MeetingSheetController::class, 'update'])->whereNumber('sheet');
            Route::post('/sheets/{sheet}/confirm', [MeetingSheetController::class, 'confirm'])->whereNumber('sheet');
        });
        // Its types are school-wide (P10-DEC-01): direction renames and adds
        // them from «Colegio y estructura › Institución».
        Route::get('/meeting-sheet-types', [MeetingSheetTypeController::class, 'index']);
        Route::post('/meeting-sheet-types', [MeetingSheetTypeController::class, 'store']);
        Route::patch('/meeting-sheet-types/{meetingSheetType}', [MeetingSheetTypeController::class, 'update'])->whereNumber('meetingSheetType');
        Route::get('/meeting-actions/mine', [MeetingSheetActionController::class, 'mine']);
        Route::post('/meeting-actions/{action}/complete', [MeetingSheetActionController::class, 'complete'])->whereNumber('action');

        // Session: user management (docs/superpowers/specs/2026-09-15-user-
        // management-design.md). Director-only staff onboarding + lifecycle.
        Route::get('/users', [UserController::class, 'index']);
        // Screen 26 «Año y calendario» (ClickUp 1dfd3mc4bh7): direction
        // closes the year and opens the next one, sets the periods per
        // program and enables loading in a group of a closed year.
        Route::get('/school-year', [SchoolYearController::class, 'show']);
        Route::put('/school-years/{schoolYear}', [SchoolYearController::class, 'update']);
        Route::post('/school-years/{schoolYear}/periods', [SchoolYearController::class, 'storePeriod']);
        Route::patch('/school-periods/{period}', [SchoolYearController::class, 'updatePeriod']);
        Route::delete('/school-periods/{period}', [SchoolYearController::class, 'destroyPeriod']);
        Route::post('/school-years/{schoolYear}/calendar-bodies/{body}/split', [SchoolYearController::class, 'splitBody'])
            ->whereIn('body', array_column(CalendarBody::cases(), 'value'));
        Route::post('/school-years/{schoolYear}/calendar-bodies/{body}/join', [SchoolYearController::class, 'joinBody'])
            ->whereIn('body', array_column(CalendarBody::cases(), 'value'));
        Route::post('/school-years/{schoolYear}/close', [SchoolYearController::class, 'close']);
        Route::post('/school-years/{schoolYear}/reopen', [SchoolYearController::class, 'reopen']);
        Route::post('/school-years/{schoolYear}/open-next', [SchoolYearController::class, 'openNext']);
        Route::post('/school-years/{schoolYear}/write-windows', [SchoolYearController::class, 'storeWriteWindow']);
        Route::post('/group-write-windows/{window}/end', [SchoolYearController::class, 'endWriteWindow']);

        // Screen 29 «Puesta en marcha» (ClickUp 1dfd3mc4bh8): direction reads
        // the onboarding record (loaded by Aula+ with app:onboarding-*) and
        // asks for a regime correction.
        Route::get('/onboarding', [OnboardingController::class, 'show']);
        Route::post('/onboarding/regime-correction', [OnboardingController::class, 'requestRegimeCorrection'])
            ->middleware('throttle:10,1');

        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/disable', [UserController::class, 'disable']);
        Route::post('/users/{user}/enable', [UserController::class, 'enable']);
        Route::post('/users/{user}/resend-invitation', [UserController::class, 'resendInvitation']);
    });
});
