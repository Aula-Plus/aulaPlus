<?php

use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;

afterEach(fn () => Tenancy::forget());

it('lets a director view, create and update any student in their school', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    expect($director->can('view', $student))->toBeTrue()
        ->and($director->can('create', Student::class))->toBeTrue()
        ->and($director->can('update', $student))->toBeTrue();
});

it('lets a psychopedagogue view, create and update any student in their school', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    expect($psychopedagogue->can('view', $student))->toBeTrue()
        ->and($psychopedagogue->can('create', Student::class))->toBeTrue()
        ->and($psychopedagogue->can('update', $student))->toBeTrue()
        ->and($psychopedagogue->can('delete', $student))->toBeFalse();
});

it('lets a teacher view only students in a group they lead, and never create or update', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $ownGroup = Group::factory()->create(['school_id' => $school->id]);
    $ownGroup->teachers()->attach($teacher);
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);

    $own = Student::factory()->create(['school_id' => $school->id]);
    $own->groups()->attach($ownGroup, ['school_year' => 2026]);
    $other = Student::factory()->create(['school_id' => $school->id]);
    $other->groups()->attach($otherGroup, ['school_year' => 2026]);

    expect($teacher->can('view', $own))->toBeTrue()
        ->and($teacher->can('view', $other))->toBeFalse()
        ->and($teacher->can('create', Student::class))->toBeFalse()
        ->and($teacher->can('update', $own))->toBeFalse();
});

it('never authorizes across schools even for a director', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);

    expect($directorA->can('view', $studentB))->toBeFalse()
        ->and($directorA->can('update', $studentB))->toBeFalse()
        ->and($directorA->can('delete', $studentB))->toBeFalse();
});

// Specific case required by docs/prompts/02-roles-permisos.md §4: a teacher
// who does NOT teach a student can never see their clinical profile, even
// within the same school.
it('never lets a teacher without a teaching relation view a student clinical profile, even in the same school', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    expect($teacher->can('view', $student))->toBeFalse()
        ->and($teacher->can('view-clinical-profile', $student))->toBeFalse();
});

it('lets a teacher who teaches the student view them, but never the clinical profile', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => 2026]);

    expect($teacher->can('view', $student))->toBeTrue()
        ->and($teacher->can('view-clinical-profile', $student))->toBeFalse();
});

it('lets director and psychopedagogue view the full clinical profile of any student in their school', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    expect($director->can('view-clinical-profile', $student))->toBeTrue()
        ->and($psychopedagogue->can('view-clinical-profile', $student))->toBeTrue();
});
