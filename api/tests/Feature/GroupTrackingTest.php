<?php

use App\Models\Accommodation;
use App\Models\Alert;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// docs/prompts/04-seguimiento-institucional.md §3 and §7: the group tracking
// view must never expose clinical detail, only counts — verified here for
// BOTH a teacher and a director/psychopedagogue, since the per-student
// summary here is counts-only for every role (full detail lives behind
// /students/{id}/tracking, which does its own per-role gating).
it('exposes only counts/booleans per student, never clinical detail, to a teacher leading the group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $student = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Ana Gómez']);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Accommodation::factory()->create(['student_id' => $student->id, 'active' => true]);
    Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk();

    $studentSummary = collect($response->json('data.students'))->firstWhere('id', $student->id);

    expect($studentSummary)
        ->toHaveKeys(['id', 'full_name', 'open_alerts_count', 'has_active_accommodations'])
        ->and($studentSummary['open_alerts_count'])->toBe(1)
        ->and($studentSummary['has_active_accommodations'])->toBeTrue()
        ->and($studentSummary)->not->toHaveKey('accommodations')
        ->and($studentSummary)->not->toHaveKey('alerts')
        ->and(json_encode($studentSummary))->not->toContain('description');
});

it('exposes the same counts-only shape to a director', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Sanctum::actingAs($director);

    $response = $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk();

    $studentSummary = collect($response->json('data.students'))->firstWhere('id', $student->id);

    expect($studentSummary)->toHaveKeys(['id', 'full_name', 'open_alerts_count', 'has_active_accommodations'])
        ->and($studentSummary)->not->toHaveKey('accommodations')
        ->and($studentSummary)->not->toHaveKey('alerts');
});

it('reports the group trend: assessments and comments count within the period', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Assessment::factory()->create(['group_id' => $group->id]);
    Comment::factory()->forSubject($group)->create();
    Sanctum::actingAs($director);

    $response = $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk();

    $response->assertJsonPath('data.trend.assessments_count', 1)
        ->assertJsonPath('data.trend.comments_count', 1);
});

it('forbids a teacher who does not lead the group from viewing its tracking page', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertForbidden();
});
