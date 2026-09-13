<?php

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * GET /api/v1/students/{student}/results — the performance timeline the Perfil
 * de alumno chart consumes (docs/prompts/13-evaluaciones-resultados.md §2).
 */
it('returns a student results ordered by administered_at, not created_at', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    // The LATER-created result was administered EARLIER — so ordering by
    // administered_at must put it first, proving we don't sort by created_at.
    $may = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id, 'type' => 'written', 'administered_at' => '2026-05-01']);
    $march = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id, 'type' => 'oral', 'administered_at' => '2026-03-01']);

    AssessmentResult::factory()->create(['assessment_id' => $may->id, 'student_id' => $student->id, 'created_by_id' => $teacher->id, 'score' => 90]);
    AssessmentResult::factory()->create(['assessment_id' => $march->id, 'student_id' => $student->id, 'created_by_id' => $teacher->id, 'score' => 60]);

    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/students/{$student->id}/results")->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    $response->assertJsonPath('data.0.administered_at', '2026-03-01')
        ->assertJsonPath('data.0.assessment_type', 'oral')
        ->assertJsonPath('data.0.assessment_id', $march->id)
        ->assertJsonPath('data.1.administered_at', '2026-05-01')
        ->assertJsonPath('data.1.assessment_type', 'written');
});

it('lets a psychopedagogue view any student results, school-wide', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->getJson("/api/v1/students/{$student->id}/results")->assertOk()->assertJsonCount(0, 'data');
});

it('forbids a teacher with no relation to the student from viewing their results', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/students/{$student->id}/results")->assertForbidden();
});

it('never resolves a student from another school for results (tenant isolation)', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
    Sanctum::actingAs($directorA);

    $this->getJson("/api/v1/students/{$studentB->id}/results")->assertNotFound();
});
