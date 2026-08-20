<?php

use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets a director list, create, update and delete students in their school', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $create = $this->postJson('/api/students', ['full_name' => 'Ana Gómez', 'enrollment_year' => 2026]);
    $create->assertCreated()->assertJsonPath('data.full_name', 'Ana Gómez');
    $studentId = $create->json('data.id');

    $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson("/api/students/{$studentId}", [
        'full_name' => 'Ana Gómez',
        'has_therapeutic_companion' => true,
    ])->assertOk()->assertJsonPath('data.has_therapeutic_companion', true);

    $this->deleteJson("/api/students/{$studentId}")->assertNoContent();
    $this->getJson('/api/students')->assertOk()->assertJsonCount(0, 'data');
});

it('lets a director enroll a student in a group for a school year', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $create = $this->postJson('/api/students', [
        'full_name' => 'Ana Gómez',
        'enrollment_year' => 2026,
        'group_id' => $group->id,
        'school_year' => 2026,
    ]);

    $create->assertCreated()->assertJsonPath('data.groups.0.id', $group->id);
});

it('rejects a psychopedagogue trying to create or update a student', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson('/api/students', ['full_name' => 'Ana Gómez', 'enrollment_year' => 2026])->assertForbidden();
    $this->putJson("/api/students/{$student->id}", ['full_name' => 'x'])->assertForbidden();
});

it('lists only students in groups a teacher leads, but all students for school-wide roles', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $ownGroup = Group::factory()->create(['school_id' => $school->id]);
    $ownGroup->teachers()->attach($teacher);
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);

    $ownStudent = Student::factory()->create(['school_id' => $school->id]);
    $ownStudent->groups()->attach($ownGroup, ['school_year' => 2026]);
    $otherStudent = Student::factory()->create(['school_id' => $school->id]);
    $otherStudent->groups()->attach($otherGroup, ['school_year' => 2026]);

    Sanctum::actingAs($teacher);
    $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($psychopedagogue);
    $this->getJson('/api/students')->assertOk()->assertJsonCount(2, 'data');
});

it('validates required student fields', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->postJson('/api/students', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['full_name', 'enrollment_year']);
});

it('never exposes or modifies another school\'s student', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);

    Sanctum::actingAs($directorA);

    $this->getJson("/api/students/{$studentB->id}")->assertNotFound();
    $this->putJson("/api/students/{$studentB->id}", ['full_name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/students/{$studentB->id}")->assertNotFound();
});
