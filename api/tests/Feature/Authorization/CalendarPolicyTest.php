<?php

use App\Models\Calendar;
use App\Models\School;
use App\Models\User;

it('lets a user view, update and delete their own calendar', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $calendar = Calendar::factory()->create(['school_id' => $school->id, 'user_id' => $teacher->id]);

    expect($teacher->can('view', $calendar))->toBeTrue()
        ->and($teacher->can('create', Calendar::class))->toBeTrue()
        ->and($teacher->can('update', $calendar))->toBeTrue()
        ->and($teacher->can('delete', $calendar))->toBeTrue();
});

it('denies a user from viewing or writing someone else\'s calendar, regardless of role', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $calendar = Calendar::factory()->create(['school_id' => $school->id, 'user_id' => $owner->id]);

    expect($director->can('view', $calendar))->toBeFalse()
        ->and($director->can('update', $calendar))->toBeFalse()
        ->and($director->can('delete', $calendar))->toBeFalse();
});

it('never authorizes across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $ownerB = User::factory()->forSchool($schoolB)->teacher()->create();
    $calendarB = Calendar::factory()->create(['school_id' => $schoolB->id, 'user_id' => $ownerB->id]);
    $directorA = User::factory()->forSchool($schoolA)->director()->create();

    expect($directorA->can('view', $calendarB))->toBeFalse();
});
