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

    $create = $this->postJson('/api/students', ['first_name' => 'Ana', 'last_name' => 'Gómez']);
    $create->assertCreated()->assertJsonPath('data.full_name', 'Ana Gómez')
        ->assertJsonPath('data.status', 'active');
    $studentId = $create->json('data.id');

    $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson("/api/students/{$studentId}", [
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
        'status' => 'inactive',
    ])->assertOk()->assertJsonPath('data.status', 'inactive');

    $this->deleteJson("/api/students/{$studentId}")->assertNoContent();
    $this->getJson('/api/students')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects a psychopedagogue trying to create or update a student', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson('/api/students', ['first_name' => 'Ana', 'last_name' => 'Gómez'])->assertForbidden();
    $this->putJson("/api/students/{$student->id}", ['first_name' => 'x', 'last_name' => 'y'])->assertForbidden();
});

it('lists only students in groups a teacher leads, but all students for school-wide roles', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $ownGroup = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);

    Student::factory()->create(['school_id' => $school->id, 'group_id' => $ownGroup->id]);
    Student::factory()->create(['school_id' => $school->id, 'group_id' => $otherGroup->id]);

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
        ->assertJsonValidationErrors(['first_name', 'last_name']);
});

it('never exposes or modifies another school\'s student', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);

    Sanctum::actingAs($directorA);

    $this->getJson("/api/students/{$studentB->id}")->assertNotFound();
    $this->putJson("/api/students/{$studentB->id}", ['first_name' => 'x', 'last_name' => 'y'])->assertNotFound();
    $this->deleteJson("/api/students/{$studentB->id}")->assertNotFound();
});
