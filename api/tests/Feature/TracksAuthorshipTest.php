<?php

use App\Models\Accommodation;
use App\Models\Barrier;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('sets created_by_id automatically when creating a barrier, without passing it explicitly', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $barrier = Barrier::create([
        'student_id' => $student->id,
        'description' => 'Struggles with reading comprehension',
        'active' => true,
    ]);

    expect($barrier->created_by_id)->toBe($teacher->id);
});

it('sets deleted_by_id automatically when soft-deleting a barrier', function () {
    $school = School::factory()->create();
    $creator = User::factory()->forSchool($school)->teacher()->create();
    $deleter = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id, 'created_by_id' => $creator->id]);

    Sanctum::actingAs($deleter);
    $barrier->delete();

    expect($barrier->fresh()->deleted_by_id)->toBe($deleter->id);
});

it('does not overwrite an explicitly provided created_by_id', function () {
    $school = School::factory()->create();
    $actingUser = User::factory()->forSchool($school)->director()->create();
    $onBehalfOf = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($actingUser);

    $accommodation = Accommodation::create([
        'student_id' => $student->id,
        'type' => 'extra_time',
        'description' => 'Given on behalf of another user',
        'focus_area' => 'attention',
        'created_by_id' => $onBehalfOf->id,
    ]);

    expect($accommodation->created_by_id)->toBe($onBehalfOf->id);
});
