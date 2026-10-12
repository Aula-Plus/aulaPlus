<?php

use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;

it('lets psychopedagogue and director view, create, update and delete any accommodation of their school', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);

    foreach ([Role::Psychopedagogue, Role::Director] as $role) {
        $user = User::factory()->forSchool($school)->role($role)->create();

        expect($user->can('view', $accommodation))->toBeTrue()
            ->and($user->can('create', Accommodation::class))->toBeTrue()
            ->and($user->can('update', $accommodation))->toBeTrue()
            ->and($user->can('delete', $accommodation))->toBeTrue();
    }
});

// ClickUp 86e3dpbwy follow-up: a teacher proposes accommodations but never
// edits one (the response would carry the full record), and only views the
// ones that apply to a subject they teach the student.
it('lets a teacher create accommodations but never update or delete them', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);

    expect($teacher->can('create', Accommodation::class))->toBeTrue()
        ->and($teacher->can('view', $accommodation))->toBeTrue()
        ->and($teacher->can('update', $accommodation))->toBeFalse()
        ->and($teacher->can('delete', $accommodation))->toBeFalse();
});

it('lets a teacher view only the accommodations scoped to a subject they teach the student', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $taught = leadGroup($group, $teacher);
    $other = Subject::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    $inTaught = Accommodation::factory()->create(['student_id' => $student->id]);
    $inTaught->subjects()->attach($taught);
    $inOther = Accommodation::factory()->create(['student_id' => $student->id]);
    $inOther->subjects()->attach($other);
    $ofAnotherStudent = Accommodation::factory()->create([
        'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
    ]);

    expect($teacher->can('view', $inTaught))->toBeTrue()
        ->and($teacher->can('view', $inOther))->toBeFalse()
        ->and($teacher->can('view', $ofAnotherStudent))->toBeFalse();
});

it('lets only director and psychopedagogue approve an accommodation that requires external approval', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'requires_external_approval' => true,
    ]);

    expect($director->can('approve', $accommodation))->toBeTrue()
        ->and($psychopedagogue->can('approve', $accommodation))->toBeTrue()
        ->and($teacher->can('approve', $accommodation))->toBeFalse();
});

// Whether requires_external_approval/approved are in a state where approving
// makes sense is a business-state precondition, not an authorization rule —
// see AccommodationPolicy::approve() and AccommodationApprovalTest for the
// endpoint-level 422 this produces (docs/prompts/03-flujos-aprobacion-
// trazabilidad.md §3). The Policy itself authorizes based on role/tenant only,
// regardless of the accommodation's current approval state.
it('authorizes approve/reject for director and psychopedagogue regardless of the accommodation approval state', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'requires_external_approval' => false,
    ]);

    expect($director->can('approve', $accommodation))->toBeTrue()
        ->and($director->can('reject', $accommodation))->toBeTrue()
        ->and($teacher->can('approve', $accommodation))->toBeFalse()
        ->and($teacher->can('reject', $accommodation))->toBeFalse();
});

it('never authorizes across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
    $accommodationB = Accommodation::factory()->create([
        'student_id' => $studentB->id,
        'requires_external_approval' => true,
    ]);

    expect($directorA->can('view', $accommodationB))->toBeFalse()
        ->and($directorA->can('update', $accommodationB))->toBeFalse()
        ->and($directorA->can('approve', $accommodationB))->toBeFalse();
});
