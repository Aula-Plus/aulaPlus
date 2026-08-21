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
