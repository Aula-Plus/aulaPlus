<?php

use App\Models\Barrier;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;

it('lets teacher and psychopedagogue create, update and delete barriers', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);

    foreach ([$teacher, $psychopedagogue] as $user) {
        expect($user->can('view', $barrier))->toBeTrue()
            ->and($user->can('create', Barrier::class))->toBeTrue()
            ->and($user->can('update', $barrier))->toBeTrue()
            ->and($user->can('delete', $barrier))->toBeTrue();
    }
});

// doc02 §2 explicitly excludes director from Barrier create/update/delete,
// unlike Accommodation — this is the case that most needs its own test.
it('denies a director from creating, updating or deleting a barrier, even though they can view it', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);

    expect($director->can('view', $barrier))->toBeTrue()
        ->and($director->can('create', Barrier::class))->toBeFalse()
        ->and($director->can('update', $barrier))->toBeFalse()
        ->and($director->can('delete', $barrier))->toBeFalse();
});

it('never authorizes across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $teacherA = User::factory()->forSchool($schoolA)->teacher()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
    $barrierB = Barrier::factory()->create(['student_id' => $studentB->id]);

    expect($teacherA->can('view', $barrierB))->toBeFalse()
        ->and($teacherA->can('update', $barrierB))->toBeFalse();
});

// Same rule as the student itself (StudentPolicy::view): a barrier is part of
// the student's profile, so a teacher only reaches the barriers of students
// they teach — not every barrier of the school.
it('denies a teacher who does not teach the student', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    leadGroup(Group::factory()->create(['school_id' => $school->id]), $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $barrier = Barrier::factory()->create(['student_id' => $student->id]);

    expect($teacher->can('view', $barrier))->toBeFalse()
        ->and($teacher->can('update', $barrier))->toBeFalse()
        ->and($teacher->can('delete', $barrier))->toBeFalse();
});
