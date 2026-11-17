<?php

use App\Models\Accommodation;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\CurricularFramework;
use App\Models\Group;
use App\Models\GroupWriteWindow;
use App\Models\School;
use App\Models\SchoolPeriod;
use App\Models\SchoolYear;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestResult;
use App\Models\Student;
use App\Models\Unit;
use App\Models\UnitObjective;
use App\Models\User;
use App\Services\SchoolYears\SchoolYearCalendar;
use App\Support\Tenancy;
use Database\Seeders\CurricularFrameworkSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
 * Screen 26 «Año y calendario» (ClickUp 1dfd3mc4bh7): close 2026 and open
 * 2027, periods per program, read-only groups of a closed year and loading
 * re-enabled in one group until a date.
 */

beforeEach(function () {
    $this->seed(CurricularFrameworkSeeder::class);
    $this->travelTo('2026-11-20 12:00:00');
});

function framework(string $name): CurricularFramework
{
    return CurricularFramework::query()->where('name', $name)->firstOrFail();
}

/**
 * A school with ANEP Bachillerato and ANEP EBI (Ciclo Básico y Primaria)
 * active, its 2026 year open with periods for both bodies, and a director.
 *
 * @return array{school: School, director: User, year: SchoolYear}
 */
function schoolWithYear(): array
{
    $school = School::factory()->create();
    $school->curricularFrameworks()->attach(framework('ANEP Bachillerato'), ['active' => true]);
    $school->curricularFrameworks()->attach(framework('ANEP EBI'), ['active' => true]);
    $director = User::factory()->forSchool($school)->director()->create();

    $year = SchoolYear::query()->forceCreate(['school_id' => $school->id, 'year' => 2026, 'status' => 'open']);
    foreach ([['Trimestre 1', '2026-03-02'], ['Trimestre 2', '2026-06-01'], ['Trimestre 3', '2026-09-14']] as [$name, $date]) {
        SchoolPeriod::query()->forceCreate([
            'school_id' => $school->id, 'school_year_id' => $year->id,
            'calendar_body' => 'anep_upper_secondary', 'name' => $name, 'starts_on' => $date,
        ]);
    }
    foreach ([['Semestre 1', '2026-03-02'], ['Semestre 2', '2026-07-27']] as [$name, $date]) {
        SchoolPeriod::query()->forceCreate([
            'school_id' => $school->id, 'school_year_id' => $year->id,
            'calendar_body' => 'anep_basic', 'name' => $name, 'starts_on' => $date,
        ]);
    }

    return compact('school', 'director', 'year');
}

function closeYear(SchoolYear $year): void
{
    $year->update(['status' => 'closed', 'closed_at' => now()]);
    app(SchoolYearCalendar::class)->forget();
}

/**
 * @return array{teacher: User, group: Group, assessment: Assessment, student: Student}
 */
function teacherGroup(School $school, int $schoolYear = 2026): array
{
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'school_year' => $schoolYear, 'name' => '2°EMS-A']);
    $subject = leadGroup($group, $teacher);
    $assessment = Assessment::factory()->create([
        'group_id' => $group->id, 'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
    ]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => $schoolYear]);

    return compact('teacher', 'group', 'assessment', 'student');
}

function postResult(Assessment $assessment, Student $student)
{
    return test()->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'score' => 7]],
    ]);
}

// ── Reading the year ────────────────────────────────────────────────────────

it('shows direction the open year with each body\'s periods separately', function () {
    ['school' => $school, 'director' => $director] = schoolWithYear();
    Group::factory()->count(2)->create(['school_id' => $school->id, 'school_year' => 2026]);
    Sanctum::actingAs($director);

    $response = $this->getJson('/api/v1/school-year')->assertOk();

    $response->assertJsonPath('data.year.year', 2026)
        ->assertJsonPath('data.year.status', 'open')
        ->assertJsonPath('data.year.can_close', false)
        ->assertJsonPath('data.next_year', 2027)
        ->assertJsonPath('data.active_groups', 2);

    $calendars = collect($response->json('data.calendars'))->keyBy('body');
    expect($calendars->keys()->all())->toBe(['anep_basic', 'anep_upper_secondary'])
        ->and($calendars['anep_upper_secondary']['programs'])->toBe(['ANEP Bachillerato'])
        ->and($calendars['anep_upper_secondary']['can_split'])->toBeFalse()
        ->and(collect($calendars['anep_upper_secondary']['periods'])->pluck('state')->all())->toBe(['past', 'past', 'current'])
        ->and(collect($calendars['anep_basic']['periods'])->firstWhere('state', 'current')['name'])->toBe('Semestre 2');
});

it('starts a school that never used the screen with this year open', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->getJson('/api/v1/school-year')->assertOk()
        ->assertJsonPath('data.year.year', 2026)
        ->assertJsonPath('data.year.status', 'open')
        ->assertJsonPath('data.previous', null);
});

it('counts only the accommodations in force that carry over', function () {
    ['school' => $school, 'director' => $director] = schoolWithYear();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Accommodation::factory()->count(2)->create(['student_id' => $student->id, 'school_id' => $school->id, 'starts_on' => '2026-04-01']);
    Accommodation::factory()->create(['student_id' => $student->id, 'school_id' => $school->id, 'starts_on' => '2026-04-01', 'active' => false]);
    Accommodation::factory()->proposal()->create(['student_id' => $student->id, 'school_id' => $school->id, 'starts_on' => '2026-04-01']);
    Sanctum::actingAs($director);

    $this->getJson('/api/v1/school-year')->assertOk()->assertJsonPath('data.carried_accommodations', 2);
});

it('keeps the screen for direction only', function (string $role) {
    ['school' => $school, 'year' => $year] = schoolWithYear();
    $user = User::factory()->forSchool($school)->{$role}()->create();
    $period = $year->periods()->first();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/school-year')->assertForbidden();
    $this->putJson("/api/v1/school-years/{$year->id}", ['closing_enabled_from' => '2026-12-01'])->assertForbidden();
    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'calendar_body' => 'anep_basic', 'name' => 'X', 'starts_on' => '2026-10-01',
    ])->assertForbidden();
    $this->postJson("/api/v1/school-years/{$year->id}/reopen", ['confirm' => true])->assertForbidden();
    $this->patchJson("/api/v1/school-periods/{$period->id}", ['name' => 'X'])->assertForbidden();
    $this->deleteJson("/api/v1/school-periods/{$period->id}")->assertForbidden();
    $this->postJson("/api/v1/school-years/{$year->id}/close", ['confirm' => true])->assertForbidden();
    $this->postJson("/api/v1/school-years/{$year->id}/open-next", ['periods' => []])->assertForbidden();

    expect($year->fresh()->status->value)->toBe('open');
})->with(['teacher', 'psychopedagogue']);

it('never lets a director touch another school\'s year', function () {
    ['year' => $year] = schoolWithYear();
    $other = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($other)->director()->create());
    $period = SchoolPeriod::query()->withoutGlobalScopes()->where('school_year_id', $year->id)->first();

    $this->putJson("/api/v1/school-years/{$year->id}", ['closing_enabled_from' => '2026-11-01'])->assertNotFound();
    $this->postJson("/api/v1/school-years/{$year->id}/close", ['confirm' => true])->assertNotFound();
    $this->patchJson("/api/v1/school-periods/{$period->id}", ['name' => 'X'])->assertNotFound();
    $this->deleteJson("/api/v1/school-periods/{$period->id}")->assertNotFound();

    // Their own screen shows their own year, never the other school's periods.
    $this->getJson('/api/v1/school-year')->assertOk()->assertJsonPath('data.calendars', []);
    expect($year->fresh()->status->value)->toBe('open');
});

// ── Periods ─────────────────────────────────────────────────────────────────

it('lets direction add, rename and remove a period of a body in the offer', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'calendar_body' => 'anep_basic', 'name' => 'Semestre 3', 'starts_on' => '2026-11-30',
    ])->assertCreated();
    $period = SchoolPeriod::query()->where('name', 'Semestre 3')->firstOrFail();
    expect($period->school_id)->toBe($school->id);

    $this->patchJson("/api/v1/school-periods/{$period->id}", ['name' => 'Cierre', 'starts_on' => '2026-12-01'])->assertOk();
    expect($period->fresh()->name)->toBe('Cierre')->and($period->fresh()->starts_on->toDateString())->toBe('2026-12-01');

    $this->deleteJson("/api/v1/school-periods/{$period->id}")->assertNoContent();
    $this->assertModelMissing($period);
});

it('validates the period: a row of the offer, a name and a date within the year', function () {
    ['director' => $director, 'year' => $year] = schoolWithYear();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'curricular_framework_id' => framework('IB DP')->id, 'name' => 'Term 1', 'starts_on' => '2027-03-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['curricular_framework_id', 'starts_on']);

    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'calendar_body' => 'anep_basic', 'starts_on' => '2026-03-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    // A body the school does not have, an unknown body, or both at once.
    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'calendar_body' => 'ib', 'name' => 'Term 1', 'starts_on' => '2026-03-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['calendar_body']);
    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'calendar_body' => 'unesco', 'name' => 'Term 1', 'starts_on' => '2026-03-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['calendar_body']);
    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'calendar_body' => 'anep_basic', 'curricular_framework_id' => framework('ANEP EBI')->id,
        'name' => 'X', 'starts_on' => '2026-03-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['calendar_body']);

    // An ANEP program follows its body's row: never periods of its own.
    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'curricular_framework_id' => framework('ANEP EBI')->id, 'name' => 'X', 'starts_on' => '2026-03-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['curricular_framework_id']);
});

it('does not change the periods of a closed year', function () {
    ['director' => $director, 'year' => $year] = schoolWithYear();
    closeYear($year);
    $period = $year->periods()->first();
    Sanctum::actingAs($director);

    $this->patchJson("/api/v1/school-periods/{$period->id}", ['name' => 'X'])->assertUnprocessable();
    $this->deleteJson("/api/v1/school-periods/{$period->id}")->assertUnprocessable();
    expect($period->fresh()->name)->toBe($period->name);
});

// ── Closing ─────────────────────────────────────────────────────────────────

it('closes the year only from the day direction chose, after confirming', function () {
    ['director' => $director, 'year' => $year] = schoolWithYear();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/school-years/{$year->id}/close", ['confirm' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['year']);

    $this->putJson("/api/v1/school-years/{$year->id}", ['closing_enabled_from' => '2026-12-01'])
        ->assertOk()->assertJsonPath('data.year.closing_enabled_from', '2026-12-01')
        ->assertJsonPath('data.year.can_close', false);
    $this->postJson("/api/v1/school-years/{$year->id}/close", ['confirm' => true])->assertUnprocessable();

    $this->travelTo('2026-12-01 09:00:00');
    $this->postJson("/api/v1/school-years/{$year->id}/close", [])->assertUnprocessable()->assertJsonValidationErrors(['confirm']);
    $this->postJson("/api/v1/school-years/{$year->id}/close", ['confirm' => true])->assertOk()
        ->assertJsonPath('data.year.status', 'closed')
        ->assertJsonPath('data.year.closed_by_name', $director->name);

    expect($year->fresh()->closed_by_id)->toBe($director->id);
    $this->postJson("/api/v1/school-years/{$year->id}/close", ['confirm' => true])->assertUnprocessable();
});

it('rejects a closing date outside the year or the next one', function () {
    ['director' => $director, 'year' => $year] = schoolWithYear();
    Sanctum::actingAs($director);

    $this->putJson("/api/v1/school-years/{$year->id}", ['closing_enabled_from' => '2028-01-10'])->assertUnprocessable();
    $this->putJson("/api/v1/school-years/{$year->id}", ['closing_enabled_from' => '2027-02-15'])->assertOk();
});

it('deletes and moves nothing when closing: groups, enrolments and accommodations stay', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['group' => $group, 'student' => $student] = teacherGroup($school);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'school_id' => $school->id, 'starts_on' => '2026-04-01']);
    $year->update(['closing_enabled_from' => '2026-11-01']);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/school-years/{$year->id}/close", ['confirm' => true])->assertOk();

    $this->assertModelExists($group);
    $this->assertDatabaseHas('group_student', ['group_id' => $group->id, 'student_id' => $student->id, 'school_year' => 2026]);
    expect($accommodation->fresh()->isInForceOn('2026-12-02'))->toBeTrue();
});

// ── Read-only groups of a closed year ───────────────────────────────────────

it('rejects writes to a group of the closed year and keeps reads working', function () {
    ['school' => $school, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'group' => $group, 'assessment' => $assessment, 'student' => $student] = teacherGroup($school);
    closeYear($year);
    Sanctum::actingAs($teacher);

    postResult($assessment, $student)->assertForbidden()->assertJsonPath('code', 'school_year_closed');
    $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written', 'administered_at' => '2026-11-20', 'subject_id' => $assessment->subject_id,
    ])->assertForbidden()->assertJsonPath('code', 'school_year_closed');
    $this->patchJson("/api/v1/assessments/{$assessment->id}", ['purpose' => 'x'])->assertForbidden();
    $this->deleteJson("/api/v1/assessments/{$assessment->id}")->assertForbidden();
    $this->postJson("/api/v1/groups/{$group->id}/comments", ['content' => 'Observación'])->assertForbidden();
    $this->postJson('/api/v1/annual-plans', ['group_id' => $group->id, 'subject_id' => $assessment->subject_id])
        ->assertForbidden()->assertJsonPath('code', 'school_year_closed');

    $plan = AnnualPlan::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id, 'subject_id' => $assessment->subject_id]);
    $this->patchJson("/api/v1/annual-plans/{$plan->id}", ['title' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/annual-plans/{$plan->id}/units", ['title' => 'x'])->assertForbidden();

    $session = ClassSession::factory()->create(['school_id' => $school->id, 'group_id' => $group->id]);
    $this->patchJson("/api/v1/class-sessions/{$session->id}", ['start_time' => '08:00'])->assertForbidden();
    $application = ScreeningTestApplication::factory()->create(['school_id' => $school->id, 'group_id' => $group->id]);
    $this->postJson("/api/v1/screening-test-applications/{$application->id}/recalculate")->assertForbidden();
    $this->postJson("/api/v1/groups/{$group->id}/screening-test-applications", [])->assertForbidden();

    $this->assertDatabaseCount('assessment_results', 0);
    $this->assertModelExists($assessment);

    $this->getJson("/api/v1/groups/{$group->id}/assessments")->assertOk();
    $this->getJson("/api/v1/assessments/{$assessment->id}/results")->assertOk();
    $this->getJson("/api/groups/{$group->id}")->assertOk()
        ->assertJsonPath('data.year_closed', true)
        ->assertJsonPath('data.read_only', true);
});

it('leaves the groups of an open year and the student\'s own record writable', function () {
    ['school' => $school, 'year' => $year] = schoolWithYear();
    closeYear($year);
    SchoolYear::query()->forceCreate(['school_id' => $school->id, 'year' => 2027, 'status' => 'open']);
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = teacherGroup($school, 2027);
    Sanctum::actingAs($teacher);

    postResult($assessment, $student)->assertOk();
    $this->postJson("/api/v1/students/{$student->id}/comments", ['content' => 'Sigue igual'])->assertCreated();
});

it('treats groups of years before the last closed one as history too', function () {
    ['school' => $school, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = teacherGroup($school, 2025);
    closeYear($year);
    Sanctum::actingAs($teacher);

    postResult($assessment, $student)->assertForbidden();
});

// ── Loading re-enabled in one group ─────────────────────────────────────────

it('lets direction enable loading in one group until a date, then read-only again by itself', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'group' => $group, 'assessment' => $assessment, 'student' => $student] = teacherGroup($school);
    ['group' => $otherGroup] = teacherGroup($school);
    closeYear($year);

    Sanctum::actingAs($director);
    $response = $this->postJson("/api/v1/school-years/{$year->id}/write-windows", [
        'group_id' => $group->id, 'reason' => 'Exámenes de febrero', 'until_on' => '2027-02-28',
    ])->assertCreated();
    $response->assertJsonPath('data.write_windows.0.reason', 'Exámenes de febrero')
        ->assertJsonPath('data.write_windows.0.enabled_by_name', $director->name)
        ->assertJsonPath('data.write_windows.0.until_on', '2027-02-28')
        ->assertJsonPath('data.write_windows.0.active', true);
    $window = GroupWriteWindow::query()->sole();
    expect($window->enabled_by_id)->toBe($director->id)->and($window->created_at)->not->toBeNull();

    Sanctum::actingAs($teacher);
    $this->travelTo('2027-02-28 20:00:00');
    postResult($assessment, $student)->assertOk();
    $this->getJson("/api/groups/{$group->id}")->assertJsonPath('data.read_only', false)
        ->assertJsonPath('data.writable_until', '2027-02-28');
    Sanctum::actingAs($director);
    $this->getJson("/api/groups/{$otherGroup->id}")->assertJsonPath('data.read_only', true);
    Sanctum::actingAs($teacher);

    $this->travelTo('2027-03-01 12:00:00');
    postResult($assessment, $student)->assertForbidden()->assertJsonPath('code', 'school_year_closed');
});

it('lets direction end a window early, keeping its record', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'group' => $group, 'assessment' => $assessment, 'student' => $student] = teacherGroup($school);
    closeYear($year);
    $window = GroupWriteWindow::query()->forceCreate([
        'school_id' => $school->id, 'group_id' => $group->id, 'reason' => 'Exámenes', 'until_on' => '2027-02-28',
        'enabled_by_id' => $director->id,
    ]);

    Sanctum::actingAs($director);
    $this->postJson("/api/v1/group-write-windows/{$window->id}/end")->assertOk()
        ->assertJsonPath('data.write_windows.0.active', false)
        ->assertJsonPath('data.write_windows.0.ended_by_name', $director->name);

    Sanctum::actingAs($teacher);
    postResult($assessment, $student)->assertForbidden();
    $this->assertModelExists($window);
});

it('only enables loading in groups of a closed year, and only for direction', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'group' => $group] = teacherGroup($school);

    Sanctum::actingAs($director);
    $this->postJson("/api/v1/school-years/{$year->id}/write-windows", [
        'group_id' => $group->id, 'reason' => 'Exámenes', 'until_on' => '2026-12-20',
    ])->assertUnprocessable()->assertJsonValidationErrors(['group_id']);

    closeYear($year);
    $this->postJson("/api/v1/school-years/{$year->id}/write-windows", [
        'group_id' => $group->id, 'reason' => '', 'until_on' => '2026-11-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'until_on']);

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/school-years/{$year->id}/write-windows", [
        'group_id' => $group->id, 'reason' => 'Exámenes', 'until_on' => '2026-12-20',
    ])->assertForbidden();

    $foreign = Group::factory()->create(['school_year' => 2026]);
    Sanctum::actingAs($director);
    $this->postJson("/api/v1/school-years/{$year->id}/write-windows", [
        'group_id' => $foreign->id, 'reason' => 'Exámenes', 'until_on' => '2026-12-20',
    ])->assertUnprocessable()->assertJsonValidationErrors(['group_id']);
    $this->assertDatabaseCount('group_write_windows', 0);
});

// ── Opening the next year ───────────────────────────────────────────────────

it('opens 2027 carrying each period as chosen: same date, other date or not carried', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    closeYear($year);
    $periods = $year->periods()->get()->keyBy('name');
    Sanctum::actingAs($director);

    $decisions = [
        ['id' => $periods['Trimestre 1']->id, 'action' => 'same_date'],
        ['id' => $periods['Trimestre 2']->id, 'action' => 'other_date', 'starts_on' => '2027-06-07'],
        ['id' => $periods['Trimestre 3']->id, 'action' => 'skip'],
        ['id' => $periods['Semestre 1']->id, 'action' => 'same_date'],
        ['id' => $periods['Semestre 2']->id, 'action' => 'same_date'],
    ];

    $this->postJson("/api/v1/school-years/{$year->id}/open-next", ['periods' => array_slice($decisions, 0, 4)])
        ->assertUnprocessable()->assertJsonValidationErrors(['periods']);

    $this->postJson("/api/v1/school-years/{$year->id}/open-next", ['periods' => $decisions])->assertCreated()
        ->assertJsonPath('data.year.year', 2027)
        ->assertJsonPath('data.year.status', 'open')
        ->assertJsonPath('data.previous.year', 2026)
        ->assertJsonPath('data.previous.status', 'closed');

    $next = SchoolYear::query()->where('year', 2027)->sole();
    expect($next->opened_by_id)->toBe($director->id)
        ->and($next->periods()->orderBy('starts_on')->orderBy('name')->get()->map(fn ($p) => [$p->name, $p->starts_on->toDateString()])->all())
        ->toBe([
            ['Semestre 1', '2027-03-02'],
            ['Trimestre 1', '2027-03-02'],
            ['Trimestre 2', '2027-06-07'],
            ['Semestre 2', '2027-07-27'],
        ])
        // The closed year keeps its own periods.
        ->and($year->periods()->count())->toBe(5);

    $this->postJson("/api/v1/school-years/{$year->id}/open-next", ['periods' => $decisions])->assertUnprocessable();
});

it('requires the year to be closed and other dates to fall in the next year', function () {
    ['director' => $director, 'year' => $year] = schoolWithYear();
    $decisions = $year->periods()->pluck('id')->map(fn ($id) => ['id' => $id, 'action' => 'same_date'])->all();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/school-years/{$year->id}/open-next", ['periods' => $decisions])
        ->assertUnprocessable()->assertJsonValidationErrors(['year']);

    closeYear($year);
    $decisions[0] = ['id' => $decisions[0]['id'], 'action' => 'other_date', 'starts_on' => '2026-03-01'];
    $this->postJson("/api/v1/school-years/{$year->id}/open-next", ['periods' => $decisions])
        ->assertUnprocessable()->assertJsonValidationErrors(['periods.0.starts_on']);
    $decisions[0] = ['id' => $decisions[0]['id'], 'action' => 'other_date'];
    $this->postJson("/api/v1/school-years/{$year->id}/open-next", ['periods' => $decisions])
        ->assertUnprocessable()->assertJsonValidationErrors(['periods.0.starts_on']);
    expect(SchoolYear::query()->where('year', 2027)->exists())->toBeFalse();
});

it('done-criterion: close 2026 on 01/12, open 2027, and the 5.°A student keeps the accommodation', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->atLevel('primary')->create(['school_id' => $school->id, 'school_year' => 2026, 'name' => '5.°A']);
    $subject = leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => 2026]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'school_id' => $school->id, 'starts_on' => '2026-05-04']);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id, 'subject_id' => $subject->id]);

    Sanctum::actingAs($director);
    $this->putJson("/api/v1/school-years/{$year->id}", ['closing_enabled_from' => '2026-12-01'])->assertOk();
    $this->getJson('/api/v1/school-year')->assertJsonPath('data.carried_accommodations', 1);

    $this->travelTo('2026-12-01 10:00:00');
    $this->postJson("/api/v1/school-years/{$year->id}/close", ['confirm' => true])->assertOk();

    Sanctum::actingAs($teacher);
    postResult($assessment, $student)->assertForbidden();

    Sanctum::actingAs($director);
    $this->travelTo('2027-02-20 10:00:00');
    $decisions = $year->periods()->pluck('id')->map(fn ($id) => ['id' => $id, 'action' => 'same_date'])->all();
    $this->postJson("/api/v1/school-years/{$year->id}/open-next", ['periods' => $decisions])->assertCreated();

    Tenancy::useSchool($school->id);
    expect($student->fresh()->groupForYear(2027))->toBeNull()
        ->and($student->fresh()->groupForYear(2026)?->id)->toBe($group->id)
        ->and(Accommodation::query()->inForceOn('2027-03-05')->whereKey($accommodation->id)->exists())->toBeTrue();
});

it('keeps listing the closed year\'s groups and enabling loading in them once the next year is open', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'group' => $group, 'assessment' => $assessment, 'student' => $student] = teacherGroup($school);
    Group::factory()->create(['school_id' => $school->id, 'school_year' => 2027, 'name' => '3°EMS-A']);
    closeYear($year);
    SchoolYear::query()->forceCreate(['school_id' => $school->id, 'year' => 2027, 'status' => 'open']);
    $this->travelTo('2027-02-10 12:00:00');

    Sanctum::actingAs($director);
    $response = $this->getJson('/api/v1/school-year')->assertOk()
        ->assertJsonPath('data.year.year', 2027)
        ->assertJsonPath('data.year.status', 'open');
    expect(collect($response->json('data.closed_year_groups'))->pluck('id')->all())->toBe([$group->id]);

    $current = SchoolYear::query()->where('school_id', $school->id)->where('year', 2027)->sole();
    $this->postJson("/api/v1/school-years/{$current->id}/write-windows", [
        'group_id' => $group->id, 'reason' => 'Exámenes de febrero', 'until_on' => '2027-02-28',
    ])->assertCreated()->assertJsonPath('data.write_windows.0.active', true);

    Sanctum::actingAs($teacher);
    postResult($assessment, $student)->assertOk();
});

it('resolves the windows in force of a groups listing in a fixed number of queries', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    $groups = Group::factory()->count(6)->create(['school_id' => $school->id, 'school_year' => 2026]);
    closeYear($year);
    Tenancy::useSchool($school);
    GroupWriteWindow::query()->create([
        'group_id' => $groups[0]->id, 'reason' => 'Exámenes de febrero', 'until_on' => '2027-02-28', 'enabled_by_id' => $director->id,
    ]);
    Sanctum::actingAs($director);

    DB::enableQueryLog();
    $response = $this->getJson('/api/groups')->assertOk();
    $windowQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query) => str_contains($query['query'], 'group_write_windows'))
        ->count();
    DB::disableQueryLog();

    expect($windowQueries)->toBe(1);
    $byId = collect($response->json('data'))->keyBy('id');
    expect($byId[$groups[0]->id]['read_only'])->toBeFalse()
        ->and($byId[$groups[0]->id]['writable_until'])->toBe('2027-02-28')
        ->and($byId[$groups[1]->id]['read_only'])->toBeTrue();
});

// Every group-data write route behind `group.writable` answers 403
// `school_year_closed` for a group of a closed year — including the nested
// resolvers (class session → objective, result → application → group) — and
// lets the request through to the endpoint once loading is enabled.
dataset('closed-year write routes', [
    'AI generate' => fn () => ['post', fn (array $c) => "/api/v1/groups/{$c['group']->id}/assistant/generate"],
    'AI apply' => fn () => ['post', fn (array $c) => "/api/v1/ai-proposals/{$c['proposal']->id}/apply"],
    'Lo terminé' => fn () => ['patch', fn (array $c) => "/api/v1/class-sessions/{$c['session']->id}/objectives/{$c['objective']->id}"],
    'screening result' => fn () => ['patch', fn (array $c) => "/api/v1/screening-test-results/{$c['result']->id}"],
    'result scale' => fn () => ['put', fn (array $c) => "/api/v1/assessments/{$c['assessment']->id}/result-scale"],
    'update unit' => fn () => ['patch', fn (array $c) => "/api/v1/units/{$c['unit']->id}"],
    'delete unit' => fn () => ['delete', fn (array $c) => "/api/v1/units/{$c['unit']->id}"],
    'add objective' => fn () => ['post', fn (array $c) => "/api/v1/units/{$c['unit']->id}/objectives"],
    'update objective' => fn () => ['patch', fn (array $c) => "/api/v1/unit-objectives/{$c['objective']->id}"],
    'delete objective' => fn () => ['delete', fn (array $c) => "/api/v1/unit-objectives/{$c['objective']->id}"],
]);

it('blocks every group-data write of a closed year until loading is enabled', function (string $method, Closure $url) {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'group' => $group, 'assessment' => $assessment] = teacherGroup($school);
    Tenancy::useSchool($school);
    $plan = AnnualPlan::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id, 'subject_id' => $assessment->subject_id]);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);
    $objective = UnitObjective::query()->create(['unit_id' => $unit->id, 'description' => 'Objetivo', 'position' => 1]);
    $context = [
        'group' => $group,
        'assessment' => $assessment,
        'unit' => $unit,
        'objective' => $objective,
        'session' => ClassSession::factory()->create(['school_id' => $school->id, 'group_id' => $group->id]),
        'proposal' => AIProposal::factory()->create(['school_id' => $school->id, 'group_id' => $group->id, 'requested_by_id' => $teacher->id]),
        'result' => ScreeningTestResult::factory()->create([
            'screening_test_application_id' => ScreeningTestApplication::factory()->create(['school_id' => $school->id, 'group_id' => $group->id])->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
        ]),
    ];
    closeYear($year);
    Sanctum::actingAs($teacher);

    $this->json(strtoupper($method), $url($context), [])
        ->assertForbidden()->assertJsonPath('code', 'school_year_closed');

    GroupWriteWindow::query()->create([
        'group_id' => $group->id, 'reason' => 'Exámenes de febrero', 'until_on' => '2027-02-28', 'enabled_by_id' => $director->id,
    ]);
    app(SchoolYearCalendar::class)->forget();

    expect($this->json(strtoupper($method), $url($context), [])->getContent())->not->toContain('school_year_closed');
})->with('closed-year write routes');

// ── Periods per body: Cambridge and IB, «Separar por programa» ───────────────

/**
 * The school of schoolWithYear() plus Cambridge Primary, Cambridge Lower
 * Secondary and IB MYP in its offer (IB DP stays out of it).
 *
 * @return array{school: School, director: User, year: SchoolYear}
 */
function schoolWithCambridgeAndIb(): array
{
    $context = schoolWithYear();
    foreach (['Cambridge Primary', 'Cambridge Lower Secondary', 'IB MYP'] as $name) {
        $context['school']->curricularFrameworks()->attach(framework($name), ['active' => true]);
    }
    foreach ([['Term 1', '2026-03-02'], ['Term 2', '2026-08-03']] as [$name, $date]) {
        SchoolPeriod::query()->forceCreate([
            'school_id' => $context['school']->id, 'school_year_id' => $context['year']->id,
            'calendar_body' => 'cambridge', 'name' => $name, 'starts_on' => $date,
        ]);
    }

    return $context;
}

it('shows one row per body of the offer: ANEP Bachillerato, ANEP Ciclo Básico y Primaria, Cambridge and IB', function () {
    ['director' => $director] = schoolWithCambridgeAndIb();
    Sanctum::actingAs($director);

    $calendars = collect($this->getJson('/api/v1/school-year')->assertOk()->json('data.calendars'));

    expect($calendars->pluck('key')->all())->toBe(['body:anep_basic', 'body:anep_upper_secondary', 'body:cambridge', 'body:ib']);
    $byBody = $calendars->keyBy('body');
    expect($byBody['cambridge']['programs'])->toBe(['Cambridge Lower Secondary', 'Cambridge Primary'])
        ->and($byBody['cambridge']['can_split'])->toBeTrue()
        ->and(collect($byBody['cambridge']['periods'])->pluck('name')->all())->toBe(['Term 1', 'Term 2'])
        // Only one IB program in the offer: nothing to separate.
        ->and($byBody['ib']['programs'])->toBe(['IB MYP'])
        ->and($byBody['ib']['can_split'])->toBeFalse()
        ->and($byBody['ib']['periods'])->toBe([]);
});

it('splits Cambridge by program, each program starting with the body\'s periods, and joins it back', function () {
    ['director' => $director, 'year' => $year] = schoolWithCambridgeAndIb();
    Sanctum::actingAs($director);

    $response = $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/split")->assertOk()
        ->assertJsonPath('data.split_bodies', ['cambridge']);
    $rows = collect($response->json('data.calendars'))->where('body', 'cambridge')->values();
    expect($rows->pluck('name')->all())->toBe(['Cambridge Lower Secondary', 'Cambridge Primary'])
        ->and($rows->every(fn ($row) => collect($row['periods'])->pluck('name')->all() === ['Term 1', 'Term 2']))->toBeTrue()
        ->and($year->periods()->where('calendar_body', 'cambridge')->count())->toBe(0);

    // Once split, periods go to a program, not to the body.
    $primary = framework('Cambridge Primary');
    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'calendar_body' => 'cambridge', 'name' => 'Term 3', 'starts_on' => '2026-10-05',
    ])->assertUnprocessable()->assertJsonValidationErrors(['calendar_body']);
    $this->postJson("/api/v1/school-years/{$year->id}/periods", [
        'curricular_framework_id' => $primary->id, 'name' => 'Term 3', 'starts_on' => '2026-10-05',
    ])->assertCreated();
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/split")->assertUnprocessable();

    // Joining would lose Primary's Term 3: blocked until both match.
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/join")
        ->assertUnprocessable()->assertJsonValidationErrors(['body']);
    $year->periods()->where('curricular_framework_id', $primary->id)->where('name', 'Term 3')->sole()->delete();

    $response = $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/join")->assertOk()
        ->assertJsonPath('data.split_bodies', []);
    $cambridge = collect($response->json('data.calendars'))->where('body', 'cambridge')->values();
    expect($cambridge)->toHaveCount(1)
        ->and($cambridge[0]['key'])->toBe('body:cambridge')
        ->and(collect($cambridge[0]['periods'])->pluck('name')->all())->toBe(['Term 1', 'Term 2']);
});

it('splits only Cambridge or IB with two programs in the offer, in an open year, and only for direction', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithCambridgeAndIb();

    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/split")->assertForbidden();
    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/split")->assertForbidden();

    Sanctum::actingAs($director);
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/anep_basic/split")->assertUnprocessable();
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/ib/split")->assertUnprocessable();
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/join")->assertUnprocessable();
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/unesco/split")->assertNotFound();

    closeYear($year);
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/split")->assertUnprocessable();

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->director()->create());
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/split")->assertNotFound();

    expect($year->fresh()->split_calendar_bodies)->toBeNull();
});

it('keeps Cambridge split when opening the next year, carrying each program\'s periods', function () {
    ['director' => $director, 'year' => $year] = schoolWithCambridgeAndIb();
    Sanctum::actingAs($director);
    $this->postJson("/api/v1/school-years/{$year->id}/calendar-bodies/cambridge/split")->assertOk();
    closeYear($year);

    $decisions = $year->periods()->pluck('id')->map(fn ($id) => ['id' => $id, 'action' => 'same_date'])->all();
    $response = $this->postJson("/api/v1/school-years/{$year->id}/open-next", ['periods' => $decisions])->assertCreated()
        ->assertJsonPath('data.split_bodies', ['cambridge']);

    $byKey = collect($response->json('data.calendars'))->keyBy('key');
    expect(collect($byKey['program:'.framework('Cambridge Primary')->id]['periods'])->pluck('starts_on')->all())
        ->toBe(['2027-03-02', '2027-08-03'])
        ->and(collect($byKey['body:anep_basic']['periods'])->pluck('name')->all())->toBe(['Semestre 1', 'Semestre 2']);
});

// ── Group structure of a closed year (Eitán 17/11) ──────────────────────────

/**
 * @return array<string, array{0: string, 1: Closure}>
 */
dataset('closed-year structure routes', [
    'edit the group' => fn () => ['patch', fn (array $c) => ["/api/groups/{$c['group']->id}", ['name' => '2°EMS-B']]],
    'add a teacher' => fn () => ['post', fn (array $c) => ["/api/v1/groups/{$c['group']->id}/teacher-assignments", ['teacher_id' => $c['newTeacher']->id, 'subject_id' => $c['subject']->id]]],
    'remove a teacher' => fn () => ['delete', fn (array $c) => ["/api/v1/groups/{$c['group']->id}/teacher-assignments", ['teacher_id' => $c['teacher']->id, 'subject_id' => $c['subject']->id]]],
    'curricular offer' => fn () => ['put', fn (array $c) => ["/api/v1/groups/{$c['group']->id}/subjects/{$c['subject']->id}/curricular-offering", ['curricular_framework_ids' => [framework('ANEP Bachillerato')->id]]]],
    'level' => fn () => ['put', fn (array $c) => ["/api/v1/groups/{$c['group']->id}/level", ['education_level_id' => $c['level'], 'school_grade_id' => null]]],
]);

it('keeps a closed year\'s group structure read-only, and lets direction correct it while loading is enabled', function (string $method, Closure $request) {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'group' => $group, 'assessment' => $assessment] = teacherGroup($school);
    $group->update(['education_level_id' => DB::table('education_levels')->where('key', 'upper_secondary')->value('id')]);
    $context = [
        'group' => $group,
        'teacher' => $teacher,
        'newTeacher' => User::factory()->forSchool($school)->teacher()->create(),
        'subject' => $assessment->subject,
        'level' => DB::table('education_levels')->where('key', 'lower_secondary')->value('id'),
    ];
    closeYear($year);
    [$url, $payload] = $request($context);
    Sanctum::actingAs($director);

    $this->json(strtoupper($method), $url, $payload)
        ->assertForbidden()->assertJsonPath('code', 'school_year_closed');
    expect($group->fresh()->name)->toBe('2°EMS-A');

    $window = GroupWriteWindow::query()->forceCreate([
        'school_id' => $school->id, 'group_id' => $group->id, 'reason' => 'Corregir docentes', 'until_on' => '2027-02-28',
        'enabled_by_id' => $director->id,
    ]);
    app(SchoolYearCalendar::class)->forget();

    $this->json(strtoupper($method), $url, $payload)->assertSuccessful();

    // Who, when and on which group, tied to the enablement (and so its why).
    $log = AuditLog::query()->where('auditable_type', GroupWriteWindow::class)
        ->where('auditable_id', $window->id)->where('action', 'updated')->sole();
    expect($log->user_id)->toBe($director->id)
        ->and($log->created_at)->not->toBeNull()
        ->and($log->changes['loaded_under_window']['group_id'])->toBe($group->id)
        ->and($log->changes['loaded_under_window']['method'])->toBe(strtoupper($method));

    // Past its date the structure is read-only again by itself.
    $this->travelTo('2027-03-01 12:00:00');
    $this->json(strtoupper($method), $url, $payload)->assertForbidden()->assertJsonPath('code', 'school_year_closed');
})->with('closed-year structure routes');

it('never deletes, moves or adds a group of a closed year, not even with loading enabled', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['group' => $group] = teacherGroup($school);
    $open = Group::factory()->create(['school_id' => $school->id, 'school_year' => 2027]);
    closeYear($year);
    Sanctum::actingAs($director);

    $this->deleteJson("/api/groups/{$group->id}")->assertForbidden()->assertJsonPath('code', 'school_year_closed');

    GroupWriteWindow::query()->forceCreate([
        'school_id' => $school->id, 'group_id' => $group->id, 'reason' => 'Corregir', 'until_on' => '2027-02-28',
        'enabled_by_id' => $director->id,
    ]);
    app(SchoolYearCalendar::class)->forget();

    $this->deleteJson("/api/groups/{$group->id}")->assertForbidden();
    $this->assertModelExists($group);
    $this->patchJson("/api/groups/{$group->id}", ['school_year' => 2027])
        ->assertUnprocessable()->assertJsonValidationErrors(['school_year']);
    $this->patchJson("/api/groups/{$group->id}", ['school_year' => 2026, 'name' => '2°EMS-B'])->assertOk();
    $this->patchJson("/api/groups/{$open->id}", ['school_year' => 2026])
        ->assertUnprocessable()->assertJsonValidationErrors(['school_year']);
    $this->postJson('/api/groups', [
        'name' => 'Nuevo', 'school_year' => 2026,
        'education_level_id' => DB::table('education_levels')->where('key', 'primary')->value('id'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['school_year']);
    $this->postJson('/api/groups', [
        'name' => 'Nuevo', 'school_year' => 2027,
        'education_level_id' => DB::table('education_levels')->where('key', 'primary')->value('id'),
    ])->assertCreated();

    expect($group->fresh()->school_year)->toBe(2026)->and($open->fresh()->school_year)->toBe(2027);
});

it('keeps the structure endpoints for their roles and school while loading is enabled', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'group' => $group] = teacherGroup($school);
    closeYear($year);
    GroupWriteWindow::query()->forceCreate([
        'school_id' => $school->id, 'group_id' => $group->id, 'reason' => 'Corregir', 'until_on' => '2027-02-28',
        'enabled_by_id' => $director->id,
    ]);

    foreach ([$teacher, User::factory()->forSchool($school)->psychopedagogue()->create()] as $user) {
        Sanctum::actingAs($user);
        $this->patchJson("/api/groups/{$group->id}", ['name' => 'X'])->assertForbidden();
        $this->postJson("/api/v1/groups/{$group->id}/teacher-assignments", ['teacher_id' => $teacher->id, 'subject_id' => 1])->assertForbidden();
    }

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->director()->create());
    $this->patchJson("/api/groups/{$group->id}", ['name' => 'X'])->assertNotFound();
    expect($group->fresh()->name)->toBe('2°EMS-A');
});

it('tells the curricular offer screen which groups are read-only', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['group' => $group] = teacherGroup($school);
    closeYear($year);
    Sanctum::actingAs($director);

    $row = collect($this->getJson('/api/v1/curricular-offerings')->assertOk()->json('data'))
        ->firstWhere('group.id', $group->id);
    expect($row['group']['read_only'])->toBeTrue();
});

it('blocks turning an accommodation off for an assessment of a closed year', function () {
    ['school' => $school, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = teacherGroup($school);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'school_id' => $school->id, 'starts_on' => '2026-04-01']);
    closeYear($year);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/instance-overrides", [
        'assessment_id' => $assessment->id, 'reason' => 'No aplica',
    ])->assertForbidden()->assertJsonPath('code', 'school_year_closed');
    $this->assertDatabaseCount('accommodation_instance_overrides', 0);
});

// ── «Deshacer el cierre» ────────────────────────────────────────────────────

it('lets direction undo the closing after confirming, recording who and when', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = teacherGroup($school);
    $year->update(['closing_enabled_from' => '2026-11-01']);
    Sanctum::actingAs($director);
    $this->postJson("/api/v1/school-years/{$year->id}/close", ['confirm' => true])->assertOk()
        ->assertJsonPath('data.year.can_reopen', true);

    $this->postJson("/api/v1/school-years/{$year->id}/reopen", [])
        ->assertUnprocessable()->assertJsonValidationErrors(['confirm']);

    $this->travelTo('2026-12-02 09:30:00');
    $this->postJson("/api/v1/school-years/{$year->id}/reopen", ['confirm' => true])->assertOk()
        ->assertJsonPath('data.year.status', 'open')
        ->assertJsonPath('data.year.can_reopen', false)
        ->assertJsonPath('data.year.can_close', true)
        ->assertJsonPath('data.year.reopened_by_name', $director->name);

    $fresh = $year->fresh();
    expect($fresh->reopened_by_id)->toBe($director->id)
        ->and($fresh->reopened_at->toDateString())->toBe('2026-12-02')
        ->and($fresh->closed_at)->toBeNull()
        ->and($year->periods()->count())->toBe(5)
        ->and(AuditLog::query()->where('auditable_type', SchoolYear::class)->where('auditable_id', $year->id)->count())->toBeGreaterThanOrEqual(2);

    // The groups are writable again.
    Sanctum::actingAs($teacher);
    postResult($assessment, $student)->assertOk();

    // Not closed any more: nothing to undo.
    Sanctum::actingAs($director);
    $this->postJson("/api/v1/school-years/{$year->id}/reopen", ['confirm' => true])->assertUnprocessable();
});

it('does not undo the closing once the next year is open', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    closeYear($year);
    $next = SchoolYear::query()->forceCreate(['school_id' => $school->id, 'year' => 2027, 'status' => 'open']);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/school-years/{$year->id}/reopen", ['confirm' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['year']);
    $this->getJson('/api/v1/school-year')->assertJsonPath('data.year.year', 2027)->assertJsonPath('data.year.can_reopen', false);

    expect($year->fresh()->status->value)->toBe('closed')->and($next->fresh()->status->value)->toBe('open');
});

it('never lets anyone else undo the closing', function () {
    ['school' => $school, 'year' => $year] = schoolWithYear();
    closeYear($year);

    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());
    $this->postJson("/api/v1/school-years/{$year->id}/reopen", ['confirm' => true])->assertForbidden();
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->director()->create());
    $this->postJson("/api/v1/school-years/{$year->id}/reopen", ['confirm' => true])->assertNotFound();

    expect($year->fresh()->status->value)->toBe('closed');
});

// ── First visit ─────────────────────────────────────────────────────────────

it('creates no year when someone other than direction opens the screen', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());

    $this->getJson('/api/v1/school-year')->assertForbidden();
    expect(SchoolYear::query()->withoutGlobalScopes()->where('school_id', $school->id)->exists())->toBeFalse();
});

it('starts on the groups\' year on a first visit in February, not on the new calendar year', function () {
    $school = School::factory()->create();
    Group::factory()->create(['school_id' => $school->id, 'school_year' => 2026]);
    Group::factory()->create(['school_id' => $school->id, 'school_year' => 2028]);
    $this->travelTo('2027-02-10 12:00:00');
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->getJson('/api/v1/school-year')->assertOk()->assertJsonPath('data.year.year', 2026);
});

// ── Route guard ─────────────────────────────────────────────────────────────

it('puts every write route on a group or its data behind group.writable', function () {
    // Writes that are deliberately not blocked by a closed year (the
    // student's own record has no group parameter and carries over by
    // itself): discarding an AI proposal changes nothing of the group.
    $exempt = [
        'POST api/v1/ai-proposals/{ai_proposal}/discard',
    ];
    $parameters = ['group', 'assessment', 'annualPlan', 'unit', 'objective', 'classSession', 'application', 'result', 'ai_proposal'];

    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->filter(fn ($route) => array_intersect($route->parameterNames(), $parameters) !== []);
    expect($writes->count())->toBeGreaterThan(20);

    $missing = $writes
        ->reject(fn ($route) => in_array('group.writable', $route->gatherMiddleware(), true))
        ->map(fn ($route) => collect($route->methods())->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])->first().' '.$route->uri())
        ->reject(fn (string $route) => in_array($route, $exempt, true))
        ->values()->all();

    expect($missing)->toBe([]);
});

// ── The roster of a closed year's group ─────────────────────────────────────

it('keeps the roster of a closed year\'s group read-only unless loading is enabled', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    ['group' => $group, 'student' => $student] = teacherGroup($school);
    ['group' => $otherGroup] = teacherGroup($school);
    $newcomer = Student::factory()->create(['school_id' => $school->id]);
    closeYear($year);
    Sanctum::actingAs($director);

    // Editing the student re-sends their own group: still allowed.
    $this->patchJson("/api/students/{$student->id}", [
        'full_name' => 'Nombre corregido', 'group_id' => $group->id, 'school_year' => 2026,
    ])->assertOk();

    // Moving them to another group of the closed year, or enrolling someone new, is not.
    $this->patchJson("/api/students/{$student->id}", [
        'full_name' => 'Nombre corregido', 'group_id' => $otherGroup->id, 'school_year' => 2026,
    ])->assertForbidden()->assertJsonPath('code', 'school_year_closed');
    $this->patchJson("/api/students/{$newcomer->id}", [
        'full_name' => $newcomer->full_name, 'group_id' => $group->id, 'school_year' => 2026,
    ])->assertForbidden()->assertJsonPath('code', 'school_year_closed');
    $this->postJson('/api/students', [
        'full_name' => 'Alumno nuevo', 'enrollment_year' => 2026, 'group_id' => $group->id, 'school_year' => 2026,
    ])->assertForbidden()->assertJsonPath('code', 'school_year_closed');
    expect($student->fresh()->groupForYear(2026)->id)->toBe($group->id)
        ->and($newcomer->fresh()->groupForYear(2026))->toBeNull();

    // While direction enables loading in the group, the move goes through and is recorded.
    $this->postJson("/api/v1/school-years/{$year->id}/write-windows", [
        'group_id' => $otherGroup->id, 'reason' => 'Corrección de lista', 'until_on' => '2027-02-28',
    ])->assertCreated();
    $this->patchJson("/api/students/{$student->id}", [
        'full_name' => 'Nombre corregido', 'group_id' => $otherGroup->id, 'school_year' => 2026,
    ])->assertOk();
    expect($student->fresh()->groupForYear(2026)->id)->toBe($otherGroup->id)
        ->and(AuditLog::query()->where('auditable_type', GroupWriteWindow::class)->where('action', 'updated')
            ->sole()->changes['loaded_under_window']['group_id'])->toBe($otherGroup->id);
});

it('rejects enrolling a student under a school year other than the group\'s own', function () {
    ['school' => $school, 'director' => $director, 'year' => $year] = schoolWithYear();
    closeYear($year);
    SchoolYear::query()->forceCreate(['school_id' => $school->id, 'year' => 2027, 'status' => 'open']);
    ['group' => $group] = teacherGroup($school, 2027);
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    // A 2027 group (open) filed under the closed 2026 row would rewrite 2026's roster.
    $this->patchJson("/api/students/{$student->id}", [
        'full_name' => $student->full_name, 'group_id' => $group->id, 'school_year' => 2026,
    ])->assertUnprocessable()->assertJsonValidationErrors('school_year');
});
