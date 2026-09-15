<?php

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Build an assessment owned by a teacher who leads a group, plus a student
 * enrolled in that group.
 *
 * @return array{teacher: User, group: Group, assessment: Assessment, student: Student}
 */
function assessmentWithEnrolledStudent(School $school): array
{
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    return compact('teacher', 'group', 'assessment', 'student');
}

it('lets the owning teacher upsert results for enrolled students', function () {
    $school = School::factory()->create();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = assessmentWithEnrolledStudent($school);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [
            ['student_id' => $student->id, 'score' => 85.5, 'feedback' => 'Buen desempeño'],
        ],
    ])->assertOk()->assertJsonPath('data.0.student_id', $student->id);

    $this->assertDatabaseHas('assessment_results', [
        'assessment_id' => $assessment->id,
        'student_id' => $student->id,
        'feedback' => 'Buen desempeño',
        'created_by_id' => $teacher->id,
        'school_id' => $school->id,
    ]);
    expect((float) AssessmentResult::first()->score)->toBe(85.5);
});

it('updates an existing result on re-upload instead of duplicating it', function () {
    $school = School::factory()->create();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = assessmentWithEnrolledStudent($school);
    Sanctum::actingAs($teacher);

    $payload = fn (float $score) => ['results' => [['student_id' => $student->id, 'score' => $score]]];

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", $payload(50))->assertOk();
    $this->postJson("/api/v1/assessments/{$assessment->id}/results", $payload(70))->assertOk();

    // The unique (assessment_id, student_id) constraint means one row, updated.
    $this->assertDatabaseCount('assessment_results', 1);
    expect((float) AssessmentResult::first()->score)->toBe(70.0);
});

it('rejects (422) a student that is not enrolled in the assessment group', function () {
    $school = School::factory()->create();
    ['teacher' => $teacher, 'assessment' => $assessment] = assessmentWithEnrolledStudent($school);
    $strangerStudent = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $strangerStudent->id, 'score' => 60]],
    ])->assertUnprocessable()->assertJsonValidationErrorFor('results.0.student_id');

    $this->assertDatabaseCount('assessment_results', 0);
});

it('forbids a teacher who does not own the assessment from creating results', function () {
    $school = School::factory()->create();
    ['assessment' => $assessment, 'student' => $student] = assessmentWithEnrolledStudent($school);
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($otherTeacher);

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'score' => 60]],
    ])->assertForbidden();
});

// docs/prompts/13-evaluaciones-resultados.md §3: a school-wide role can READ the
// results (not clinical data) but still cannot create them — only the owner.
it('lets a director read results but never create them', function () {
    $school = School::factory()->create();
    ['assessment' => $assessment, 'student' => $student, 'teacher' => $teacher] = assessmentWithEnrolledStudent($school);
    AssessmentResult::factory()->create([
        'assessment_id' => $assessment->id,
        'student_id' => $student->id,
        'created_by_id' => $teacher->id,
    ]);
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $this->getJson("/api/v1/assessments/{$assessment->id}/results")->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'score' => 99]],
    ])->assertForbidden();
});

it('never resolves an assessment from another school for results (tenant isolation)', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    ['assessment' => $assessmentB] = assessmentWithEnrolledStudent($schoolB);
    Sanctum::actingAs($directorA);

    $this->getJson("/api/v1/assessments/{$assessmentB->id}/results")->assertNotFound();
});
