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

it('lets a psychopedagogue view any student but not create or update one', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    expect($psychopedagogue->can('view', $student))->toBeTrue()
        ->and($psychopedagogue->can('create', Student::class))->toBeFalse()
        ->and($psychopedagogue->can('update', $student))->toBeFalse();
});

it('lets a teacher view only students in a group they lead, and never create or update', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $ownGroup = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);

    $own = Student::factory()->create(['school_id' => $school->id, 'group_id' => $ownGroup->id]);
    $other = Student::factory()->create(['school_id' => $school->id, 'group_id' => $otherGroup->id]);

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
