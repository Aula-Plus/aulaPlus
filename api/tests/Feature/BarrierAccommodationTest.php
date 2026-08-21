<?php

use App\Models\Accommodation;
use App\Models\Barrier;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets a teacher propose linking an accommodation to a barrier', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodation->id])
        ->assertCreated()
        ->assertJsonPath('data.proposed_by_id', $teacher->id)
        ->assertJsonPath('data.validated', false);

    $this->getJson("/api/v1/barriers/{$barrier->id}/accommodations")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('returns 422 when the user who proposed a link also tries to validate it (four-eyes rule)', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodation->id])
        ->assertCreated();

    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations/{$accommodation->id}/validate")
        ->assertStatus(422);

    expect($barrier->accommodations()->first()->pivot->validated)->toBeFalsy();
});

it('lets a different director/psychopedagogue validate a proposed link', function () {
    $school = School::factory()->create();
    $proposer = User::factory()->forSchool($school)->teacher()->create();
    $validator = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);

    Sanctum::actingAs($proposer);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodation->id])
        ->assertCreated();

    Sanctum::actingAs($validator);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations/{$accommodation->id}/validate")
        ->assertOk()
        ->assertJsonPath('data.validated', true)
        ->assertJsonPath('data.validated_by_id', $validator->id);
});

it('rejects a teacher trying to validate a barrier-accommodation link', function () {
    $school = School::factory()->create();
    $proposer = User::factory()->forSchool($school)->psychopedagogue()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);

    Sanctum::actingAs($proposer);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodation->id])
        ->assertCreated();

    Sanctum::actingAs($otherTeacher);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations/{$accommodation->id}/validate")
        ->assertForbidden();
});

it('never lets a barrier be linked to an accommodation from another school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $teacher = User::factory()->forSchool($schoolA)->teacher()->create();
    $studentA = Student::factory()->create(['school_id' => $schoolA->id]);
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
    $barrier = Barrier::factory()->create(['student_id' => $studentA->id]);
    $accommodationB = Accommodation::factory()->create(['student_id' => $studentB->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodationB->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['accommodation_id']);
});
