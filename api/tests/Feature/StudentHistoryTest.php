<?php

use App\Models\Accommodation;
use App\Models\Barrier;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns 403 for a teacher without a relation to the student requesting history', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/students/{$student->id}/history")->assertForbidden();
});

it('lets a director see the paginated audit timeline for a student, most recent first', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    // Pin the initial focus_area to a value different from the one the update
    // sets below: AccommodationFactory picks focus_area at random, so if it
    // happened to pick 'literacy' the update would be a no-op and emit no
    // 'updated' audit log (flaky ~1/3 of runs).
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'focus_area' => 'mathematics']);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);
    $accommodation->update(['focus_area' => 'literacy']);

    $response = $this->getJson("/api/v1/students/{$student->id}/history")->assertOk();

    $actions = collect($response->json('data'))->pluck('action');

    expect($actions->first())->toBe('updated')
        ->and($actions)->toContain('created')
        ->and($response->json('meta.total') ?? count($response->json('data')))->toBeGreaterThanOrEqual(3);
});

it('a psychopedagogue can also see the student history', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Accommodation::factory()->create(['student_id' => $student->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->getJson("/api/v1/students/{$student->id}/history")->assertOk();
});
