<?php

use App\Enums\AccommodationReviewStatus;
use App\Models\Accommodation;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;

it('lets psychopedagogue view, create, update and delete any accommodation of their school', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id]);
    $user = User::factory()->forSchool($school)->psychopedagogue()->create();

    expect($user->can('view', $accommodation))->toBeTrue()
        ->and($user->can('create', Accommodation::class))->toBeTrue()
        ->and($user->can('update', $accommodation))->toBeTrue()
        ->and($user->can('delete', $accommodation))->toBeTrue();
});

// ClickUp 86e3qkwkk: direction reads every accommodation and proposes new
// ones, but only edits its proposals while they are under review.
it('lets direction view and propose, and edit only proposals under review', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $confirmed = Accommodation::factory()->create(['student_id' => $student->id]);
    $pending = Accommodation::factory()->proposal()->create(['student_id' => $student->id]);
    $returned = Accommodation::factory()->proposal(AccommodationReviewStatus::Returned)->create(['student_id' => $student->id]);
    $rejected = Accommodation::factory()->proposal(AccommodationReviewStatus::Rejected)->create(['student_id' => $student->id]);
    $director = User::factory()->forSchool($school)->director()->create();

    expect($director->can('view', $confirmed))->toBeTrue()
        ->and($director->can('create', Accommodation::class))->toBeTrue()
        ->and($director->can('update', $confirmed))->toBeFalse()
        ->and($director->can('delete', $confirmed))->toBeFalse()
        ->and($director->can('update', $pending))->toBeTrue()
        ->and($director->can('update', $returned))->toBeTrue()
        ->and($director->can('update', $rejected))->toBeFalse()
        ->and($director->can('confirm', $pending))->toBeFalse()
        ->and($director->can('withdraw', $pending))->toBeTrue();
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

it('keeps the review steps of a proposal to their role', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $proposal = Accommodation::factory()->proposal()->create(['student_id' => $student->id]);

    foreach (['confirm', 'returnProposal', 'reject'] as $ability) {
        expect($psychopedagogue->can($ability, $proposal))->toBeTrue()
            ->and($director->can($ability, $proposal))->toBeFalse()
            ->and($teacher->can($ability, $proposal))->toBeFalse();
    }
    foreach (['resubmit', 'withdraw'] as $ability) {
        expect($director->can($ability, $proposal))->toBeTrue()
            ->and($psychopedagogue->can($ability, $proposal))->toBeFalse()
            ->and($teacher->can($ability, $proposal))->toBeFalse();
    }
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
