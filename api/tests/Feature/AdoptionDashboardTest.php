<?php

use App\Models\School;
use App\Models\UsageEvent;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// docs/prompts/04-seguimiento-institucional.md §6
it('returns 403 for a role that is not director', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard")->assertForbidden();

    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);
    $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard")->assertForbidden();
});

it('returns 403 for a director of a different school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    Sanctum::actingAs($directorA);

    $this->getJson("/api/v1/schools/{$schoolB->id}/adoption-dashboard")->assertForbidden();
});

it('lets the director of the school see the adoption dashboard', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['teacher_login_rate_30d', 'teacher_planning_rate_30d', 'weekly_login_series', 'weekly_content_series'],
        ]);
});

// docs/prompts/04-seguimiento-institucional.md §6: the % active teachers
// calculation must be verified against an explicit, known dataset built in
// the test itself — not the seeder.
it('computes the % of active teachers correctly against a known dataset', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    // 4 teachers total: 3 log in within 30 days, 1 does not.
    $activeTeachers = User::factory()->forSchool($school)->teacher()->count(3)->create();
    $inactiveTeacher = User::factory()->forSchool($school)->teacher()->create();

    foreach ($activeTeachers as $teacher) {
        UsageEvent::factory()->create([
            'school_id' => $school->id,
            'user_id' => $teacher->id,
            'event_type' => 'login',
            'created_at' => now()->subDays(2),
        ]);
    }

    // A login event outside the 30-day window must not count.
    UsageEvent::factory()->create([
        'school_id' => $school->id,
        'user_id' => $inactiveTeacher->id,
        'event_type' => 'login',
        'created_at' => now()->subDays(45),
    ]);

    // Only 1 of the 4 teachers created a planning object in the last 30 days.
    UsageEvent::factory()->create([
        'school_id' => $school->id,
        'user_id' => $activeTeachers->first()->id,
        'event_type' => 'annual_plan.created',
        'created_at' => now()->subDays(1),
    ]);

    // Noise: a non-teacher user's login must not affect the percentage.
    UsageEvent::factory()->create([
        'school_id' => $school->id,
        'user_id' => $director->id,
        'event_type' => 'login',
        'created_at' => now()->subDays(1),
    ]);

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard")->assertOk();

    // 3 of 4 teachers logged in within 30 days = 75%.
    expect($response->json('data.teacher_login_rate_30d'))->toEqual(75.0);
    // 1 of 4 teachers created a planning object within 30 days = 25%.
    expect($response->json('data.teacher_planning_rate_30d'))->toEqual(25.0);
});

it('returns 0% when the school has no teachers', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $response = $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard")->assertOk();

    expect($response->json('data.teacher_login_rate_30d'))->toEqual(0.0)
        ->and($response->json('data.teacher_planning_rate_30d'))->toEqual(0.0);
});

it('splits the weekly content series by type', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();

    foreach (['annual_plan.created', 'class_session.created', 'class_session.created', 'assessment.created'] as $type) {
        UsageEvent::factory()->create([
            'school_id' => $school->id,
            'user_id' => $teacher->id,
            'event_type' => $type,
            'created_at' => now(),
        ]);
    }

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard")->assertOk();

    $series = $response->json('data.weekly_content_by_type');
    expect($series)->toHaveCount(8);
    expect(last($series))->toMatchArray(['annual_plans' => 1, 'class_sessions' => 2, 'assessments' => 1]);
    expect($series[0])->toMatchArray(['annual_plans' => 0, 'class_sessions' => 0, 'assessments' => 0]);
});

it('lists teachers alphabetically with this month counts only, for the director', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $zoe = User::factory()->forSchool($school)->teacher()->create(['name' => 'Zoe Pérez']);
    $ana = User::factory()->forSchool($school)->teacher()->create(['name' => 'ana Fernández']);

    UsageEvent::factory()->create([
        'school_id' => $school->id, 'user_id' => $ana->id,
        'event_type' => 'class_session.created', 'created_at' => now()->startOfMonth()->addMinute(),
    ]);
    UsageEvent::factory()->create([
        'school_id' => $school->id, 'user_id' => $ana->id,
        'event_type' => 'class_session.created', 'created_at' => now()->startOfMonth()->subDay(),
    ]);

    // A teacher from another school must never appear.
    User::factory()->forSchool(School::factory()->create())->teacher()->create();

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard/teachers")->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['ana Fernández', 'Zoe Pérez']);
    expect($response->json('data.0.month_counts'))->toBe(['annual_plans' => 0, 'class_sessions' => 1, 'assessments' => 0]);
    expect($response->json('data.0'))->not->toHaveKeys(['last_login', 'last_login_at', 'total']);
});

it('forbids the per-teacher views for non-directors and other schools', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $otherDirector = User::factory()->forSchool(School::factory()->create())->director()->create();

    foreach ([$teacher, $otherDirector] as $actor) {
        Sanctum::actingAs($actor);
        $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard/teachers")->assertForbidden();
        $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard/teachers/{$teacher->id}/usage")->assertForbidden();
    }
});

it('returns the last login only as a coarse range', function (?int $daysAgo, string $expected) {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();

    if ($daysAgo !== null) {
        UsageEvent::factory()->create([
            'school_id' => $school->id, 'user_id' => $teacher->id,
            'event_type' => 'login', 'created_at' => now()->subDays($daysAgo),
        ]);
    }

    Sanctum::actingAs($director);
    $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard/teachers/{$teacher->id}/usage")
        ->assertOk()
        ->assertExactJson(['data' => ['last_login_range' => $expected]]);
})->with([
    'never' => [null, 'never'],
    'recent' => [0, 'this_week'],
    'a week and a bit' => [8, 'within_10_days'],
    'long ago' => [30, 'over_10_days'],
]);

it('returns 404 for a non-teacher or another school user on the usage detail', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $foreignTeacher = User::factory()->forSchool(School::factory()->create())->teacher()->create();

    Sanctum::actingAs($director);
    $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard/teachers/{$foreignTeacher->id}/usage")->assertNotFound();
    $this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard/teachers/{$director->id}/usage")->assertNotFound();
});
