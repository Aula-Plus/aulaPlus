<?php

use App\Models\Accommodation;
use App\Models\Alert;
use App\Models\Barrier;
use App\Models\Comment;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

it('returns aggregated tracking data for a student to a psychopedagogue, including clinical detail', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Accommodation::factory()->create(['student_id' => $student->id, 'active' => true]);
    Barrier::factory()->create(['student_id' => $student->id, 'active' => true]);
    Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);
    Comment::factory()->forSubject($student)->create(['content' => 'Nota de seguimiento']);
    Sanctum::actingAs($psychopedagogue);

    $response = $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk();

    $response->assertJsonPath('data.accommodations_count', 1)
        ->assertJsonPath('data.barriers_count', 1)
        ->assertJsonPath('data.open_alerts_count', 1)
        ->assertJsonCount(1, 'data.accommodations')
        ->assertJsonCount(1, 'data.barriers')
        ->assertJsonCount(1, 'data.alerts')
        ->assertJsonCount(1, 'data.recent_comments');
});

// docs/prompts/04-seguimiento-institucional.md §7: no endpoint in this
// session may expose clinical data to a role that shouldn't see it per the
// Sesión 2 policy.
it('gives a teacher only counts, never clinical detail, on the same student tracking view', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Accommodation::factory()->create(['student_id' => $student->id, 'active' => true]);
    Barrier::factory()->create(['student_id' => $student->id, 'active' => true]);
    Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk();

    $response->assertJsonPath('data.accommodations_count', 1)
        ->assertJsonPath('data.barriers_count', 1)
        ->assertJsonPath('data.open_alerts_count', 1)
        ->assertJsonMissingPath('data.accommodations')
        ->assertJsonMissingPath('data.barriers')
        ->assertJsonMissingPath('data.alerts');
});

it('forbids a teacher with no relation to the student from viewing their tracking page', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/students/{$student->id}/tracking")->assertForbidden();
});

it('caches the raw student tracking aggregation for 60 seconds', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk();

    expect(Cache::has("student-tracking.{$student->id}"))->toBeTrue();

    // A new comment created after the first request should NOT appear until
    // the 60s cache expires — proves the aggregation itself is cached.
    Comment::factory()->forSubject($student)->create(['content' => 'Comentario nuevo']);

    $response = $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk();
    expect($response->json('data.recent_comments'))->toBeEmpty();
});
