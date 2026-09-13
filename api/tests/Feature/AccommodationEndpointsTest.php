<?php

use App\Models\Accommodation;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Session 8 §1 (docs/prompts/18-ajustes-categoria-instancia.md): the
 * Accommodation create/edit endpoints that Session 1 never built, where the
 * new `category` (access | content | criteria) must be supplied.
 */
it('lets a staff member create an accommodation with a category', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $response = $this->postJson("/api/v1/students/{$student->id}/accommodations", [
        'category' => 'criteria',
        'type' => 'spelling_not_scored',
        'description' => 'La ortografía no puntúa en Idioma Español.',
        'focus_area' => 'literacy',
    ])->assertCreated();

    $response->assertJsonPath('data.category', 'criteria')
        ->assertJsonPath('data.student_id', $student->id);

    $this->assertDatabaseHas('accommodations', [
        'student_id' => $student->id,
        'category' => 'criteria',
        'school_id' => $school->id,
        'created_by_id' => $director->id,
    ]);
});

it('rejects creating an accommodation without a category (422)', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", [
        'type' => 'extra_time',
        'description' => 'Más tiempo en escritas.',
        'focus_area' => 'attention',
    ])->assertUnprocessable()->assertJsonValidationErrorFor('category');

    $this->assertDatabaseCount('accommodations', 0);
});

it('rejects an invalid category value (422)', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", [
        'category' => 'not-a-real-category',
        'type' => 'extra_time',
        'description' => 'x',
        'focus_area' => 'attention',
    ])->assertUnprocessable()->assertJsonValidationErrorFor('category');
});

it('lets a staff member edit an accommodation category', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $accommodation = Accommodation::factory()->create([
        'school_id' => $school->id,
        'category' => 'access',
    ]);
    Sanctum::actingAs($director);

    $this->patchJson("/api/v1/accommodations/{$accommodation->id}", [
        'category' => 'content',
    ])->assertOk()->assertJsonPath('data.category', 'content');

    $this->assertDatabaseHas('accommodations', [
        'id' => $accommodation->id,
        'category' => 'content',
    ]);
});

it('rejects editing an accommodation without a category (422)', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $accommodation = Accommodation::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $this->patchJson("/api/v1/accommodations/{$accommodation->id}", [
        'description' => 'Descripción actualizada.',
    ])->assertUnprocessable()->assertJsonValidationErrorFor('category');
});

it('never resolves an accommodation from another school (tenant isolation)', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $accommodationB = Accommodation::factory()->create(['school_id' => $schoolB->id]);
    Sanctum::actingAs($directorA);

    // The SchoolScope hides it entirely, so route-model binding 404s.
    $this->patchJson("/api/v1/accommodations/{$accommodationB->id}", ['category' => 'access'])
        ->assertNotFound();
});
