<?php

use App\Models\Accommodation;
use App\Models\AccommodationInstanceOverride;
use App\Models\Assessment;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Happy-path graph for an instance override (docs/prompts/18-ajustes-categoria-
 * instancia.md §2): a teacher leading a group, a student enrolled in it, an
 * accommodation for that student, and an assessment for the group owned by the
 * teacher.
 *
 * @return array{teacher: User, group: Group, student: Student, accommodation: Accommodation, assessment: Assessment}
 */
function overrideGraph(School $school): array
{
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);

    $student = Student::factory()->create(['school_id' => $school->id]);
    $group->students()->attach($student, ['school_year' => 2026]);

    $accommodation = Accommodation::factory()->create([
        'school_id' => $school->id,
        'student_id' => $student->id,
    ]);
    $assessment = Assessment::factory()->create([
        'school_id' => $school->id,
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
    ]);

    return compact('teacher', 'group', 'student', 'accommodation', 'assessment');
}

it('lets the owning teacher deactivate an accommodation for their assessment', function () {
    $school = School::factory()->create();
    ['teacher' => $teacher, 'accommodation' => $accommodation, 'assessment' => $assessment] = overrideGraph($school);
    Sanctum::actingAs($teacher);

    $response = $this->postJson("/api/v1/accommodations/{$accommodation->id}/instance-overrides", [
        'assessment_id' => $assessment->id,
        'reason' => 'No aplica en esta evaluación oral.',
    ])->assertCreated();

    $response->assertJsonPath('data.accommodation_id', $accommodation->id)
        ->assertJsonPath('data.assessment_id', $assessment->id)
        ->assertJsonPath('data.deactivated_by_id', $teacher->id)
        ->assertJsonPath('data.reason', 'No aplica en esta evaluación oral.');

    $this->assertDatabaseHas('accommodation_instance_overrides', [
        'accommodation_id' => $accommodation->id,
        'assessment_id' => $assessment->id,
        'deactivated_by_id' => $teacher->id,
        'school_id' => $school->id,
    ]);
});

it('rejects a duplicate override for the same assessment (422, not 500)', function () {
    $school = School::factory()->create();
    ['teacher' => $teacher, 'accommodation' => $accommodation, 'assessment' => $assessment] = overrideGraph($school);
    Sanctum::actingAs($teacher);

    $payload = [
        'assessment_id' => $assessment->id,
        'reason' => 'No aplica en esta evaluación oral.',
    ];

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/instance-overrides", $payload)
        ->assertCreated();

    // A repeat POST must be a clean validation error, not a DB-constraint 500.
    $this->postJson("/api/v1/accommodations/{$accommodation->id}/instance-overrides", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('assessment_id');

    $this->assertDatabaseCount('accommodation_instance_overrides', 1);
});

it('requires a reason (422)', function () {
    $school = School::factory()->create();
    ['teacher' => $teacher, 'accommodation' => $accommodation, 'assessment' => $assessment] = overrideGraph($school);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/instance-overrides", [
        'assessment_id' => $assessment->id,
    ])->assertUnprocessable()->assertJsonValidationErrorFor('reason');

    $this->assertDatabaseCount('accommodation_instance_overrides', 0);
});

it('forbids a teacher who does not own the assessment (403)', function () {
    $school = School::factory()->create();
    ['accommodation' => $accommodation, 'assessment' => $assessment] = overrideGraph($school);
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($otherTeacher);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/instance-overrides", [
        'assessment_id' => $assessment->id,
        'reason' => 'Intento no autorizado.',
    ])->assertForbidden();

    $this->assertDatabaseCount('accommodation_instance_overrides', 0);
});

it('forbids a director from creating an override (not the owning teacher)', function () {
    $school = School::factory()->create();
    ['accommodation' => $accommodation, 'assessment' => $assessment] = overrideGraph($school);
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/instance-overrides", [
        'assessment_id' => $assessment->id,
        'reason' => 'x',
    ])->assertForbidden();
});

it('fails when the accommodation is not for a student in the assessment group (422)', function () {
    $school = School::factory()->create();
    ['teacher' => $teacher, 'assessment' => $assessment] = overrideGraph($school);

    // An accommodation for a different student, NOT enrolled in the group.
    $outsideStudent = Student::factory()->create(['school_id' => $school->id]);
    $foreignAccommodation = Accommodation::factory()->create([
        'school_id' => $school->id,
        'student_id' => $outsideStudent->id,
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/accommodations/{$foreignAccommodation->id}/instance-overrides", [
        'assessment_id' => $assessment->id,
        'reason' => 'x',
    ])->assertUnprocessable()->assertJsonValidationErrorFor('accommodation');

    $this->assertDatabaseCount('accommodation_instance_overrides', 0);
});

it('rejects an assessment_id from another school (422)', function () {
    $school = School::factory()->create();
    $otherSchool = School::factory()->create();
    ['teacher' => $teacher, 'accommodation' => $accommodation] = overrideGraph($school);
    ['assessment' => $foreignAssessment] = overrideGraph($otherSchool);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/instance-overrides", [
        'assessment_id' => $foreignAssessment->id,
        'reason' => 'x',
    ])->assertUnprocessable()->assertJsonValidationErrorFor('assessment_id');
});

it('lists the overrides for an assessment to the owning teacher', function () {
    $school = School::factory()->create();
    ['teacher' => $teacher, 'accommodation' => $accommodation, 'assessment' => $assessment] = overrideGraph($school);
    AccommodationInstanceOverride::factory()->create([
        'school_id' => $school->id,
        'accommodation_id' => $accommodation->id,
        'assessment_id' => $assessment->id,
    ]);
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/assessments/{$assessment->id}/instance-overrides")->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.assessment_id', $assessment->id)
        ->assertJsonPath('data.0.accommodation_id', $accommodation->id);
});

it('lists the overrides for an assessment to a school-wide role', function () {
    $school = School::factory()->create();
    ['accommodation' => $accommodation, 'assessment' => $assessment] = overrideGraph($school);
    AccommodationInstanceOverride::factory()->create([
        'school_id' => $school->id,
        'accommodation_id' => $accommodation->id,
        'assessment_id' => $assessment->id,
    ]);
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $this->getJson("/api/v1/assessments/{$assessment->id}/instance-overrides")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('never resolves an assessment override listing from another school (tenant isolation)', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    ['assessment' => $assessmentB] = overrideGraph($schoolB);
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    Sanctum::actingAs($directorA);

    $this->getJson("/api/v1/assessments/{$assessmentB->id}/instance-overrides")->assertNotFound();
});
