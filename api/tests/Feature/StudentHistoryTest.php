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

    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);
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
