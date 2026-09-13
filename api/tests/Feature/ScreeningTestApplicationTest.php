<?php

use App\Models\Group;
use App\Models\School;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

afterEach(fn () => Tenancy::forget());

/**
 * Applying a screening test to a group + reading it back (docs/prompts/
 * 11-pruebas-de-sondeo.md §§2-3).
 */

/**
 * @return array{school: School, group: Group, students: array<string, Student>}
 */
function screeningGroupWithStudents(): array
{
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    // Deliberately out of alphabetical order so code assignment order is tested.
    $carla = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Carla Núñez']);
    $ana = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Ana Díaz']);
    $bruno = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Bruno Pérez']);

    foreach ([$carla, $ana, $bruno] as $student) {
        $group->students()->attach($student->id, ['school_year' => now()->year]);
    }

    return ['school' => $school, 'group' => $group, 'students' => compact('ana', 'bruno', 'carla')];
}

function approvedTypeForSchool(School $school, float $low = 40, float $high = 70): ScreeningTestType
{
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    ScreeningTestDesign::factory()->for($type, 'type')->approved()->create([
        'cutoff_low' => $low,
        'cutoff_high' => $high,
    ]);

    return $type;
}

it('creates one result per active student with sequential codes in alphabetical order', function () {
    ['school' => $school, 'group' => $group, 'students' => $students] = screeningGroupWithStudents();
    $type = approvedTypeForSchool($school);
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/groups/{$group->id}/screening-test-applications", [
        'screening_test_type_id' => $type->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.results_count', 3)
        ->assertJsonPath('data.applied_by_id', $psychopedagogue->id);

    $application = ScreeningTestApplication::query()->firstOrFail();

    // "01" → Ana (alphabetically first), "02" → Bruno, "03" → Carla.
    expect($application->results()->orderBy('code')->pluck('code')->all())->toBe(['01', '02', '03']);
    expect($application->results()->where('code', '01')->value('student_id'))->toBe($students['ana']->id);
    expect($application->results()->where('code', '02')->value('student_id'))->toBe($students['bruno']->id);
    expect($application->results()->where('code', '03')->value('student_id'))->toBe($students['carla']->id);

    // Codes start null on score/color.
    expect($application->results()->whereNotNull('score')->count())->toBe(0);
});

it('cannot create an application when the type has no approved design in force', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    // Only a pending design exists — not approved.
    ScreeningTestDesign::factory()->for($type, 'type')->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/groups/{$group->id}/screening-test-applications", [
        'screening_test_type_id' => $type->id,
    ])->assertStatus(422);

    expect(ScreeningTestApplication::count())->toBe(0);
});

it('cannot create an application when the type only has a rejected design', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    ScreeningTestDesign::factory()->for($type, 'type')->rejected()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/groups/{$group->id}/screening-test-applications", [
        'screening_test_type_id' => $type->id,
    ])->assertStatus(422);
});

it('forbids a teacher and a director from creating an application', function () {
    ['school' => $school, 'group' => $group] = screeningGroupWithStudents();
    $type = approvedTypeForSchool($school);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/groups/{$group->id}/screening-test-applications", ['screening_test_type_id' => $type->id])
        ->assertForbidden();

    Sanctum::actingAs($director);
    $this->postJson("/api/v1/groups/{$group->id}/screening-test-applications", ['screening_test_type_id' => $type->id])
        ->assertForbidden();
});

it('lets a director list a group applications but forbids a teacher', function () {
    ['school' => $school, 'group' => $group] = screeningGroupWithStudents();
    $type = approvedTypeForSchool($school);
    ScreeningTestApplication::factory()->create([
        'school_id' => $school->id,
        'group_id' => $group->id,
        'screening_test_type_id' => $type->id,
    ]);

    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);
    $this->getJson("/api/v1/groups/{$group->id}/screening-test-applications")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $teacher = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/groups/{$group->id}/screening-test-applications")->assertForbidden();
});

it('exposes the code to student roster only to psychopedagogy', function () {
    ['school' => $school, 'group' => $group, 'students' => $students] = screeningGroupWithStudents();
    $type = approvedTypeForSchool($school);
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $applicationId = $this->postJson("/api/v1/groups/{$group->id}/screening-test-applications", [
        'screening_test_type_id' => $type->id,
    ])->json('data.id');

    $this->getJson("/api/v1/screening-test-applications/{$applicationId}/roster")
        ->assertOk()
        ->assertJsonPath('data.0.code', '01')
        ->assertJsonPath('data.0.full_name', 'Ana Díaz')
        ->assertJsonPath('data.0.student_id', $students['ana']->id);

    // Director and teacher cannot see the roster (spec §2: psychopedagogy only).
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);
    $this->getJson("/api/v1/screening-test-applications/{$applicationId}/roster")->assertForbidden();

    $teacher = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/screening-test-applications/{$applicationId}/roster")->assertForbidden();
});

it('returns results by code without exposing the student name or id', function () {
    ['school' => $school, 'group' => $group] = screeningGroupWithStudents();
    $type = approvedTypeForSchool($school);
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $applicationId = $this->postJson("/api/v1/groups/{$group->id}/screening-test-applications", [
        'screening_test_type_id' => $type->id,
    ])->json('data.id');

    $response = $this->getJson("/api/v1/screening-test-applications/{$applicationId}/results")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.code', '01');

    // No student name/id ever crosses into the by-code results view.
    $body = $response->getContent();
    expect($body)->not->toContain('Ana Díaz')
        ->and($body)->not->toContain('full_name')
        ->and($body)->not->toContain('student_id');

    // A teacher cannot read results.
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/screening-test-applications/{$applicationId}/results")->assertForbidden();
});
