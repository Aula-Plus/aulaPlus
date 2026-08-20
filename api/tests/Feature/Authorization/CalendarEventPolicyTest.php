<?php

use App\Models\Calendar;
use App\Models\CalendarEvent;
use App\Models\School;
use App\Models\User;

it('lets a user view, update and delete an event on their own calendar', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $calendar = Calendar::factory()->create(['school_id' => $school->id, 'user_id' => $teacher->id]);
    $event = CalendarEvent::factory()->create(['school_id' => $school->id]);
    $calendar->events()->attach($event);

    expect($teacher->can('view', $event))->toBeTrue()
        ->and($teacher->can('create', CalendarEvent::class))->toBeTrue()
        ->and($teacher->can('update', $event))->toBeTrue()
        ->and($teacher->can('delete', $event))->toBeTrue();
});

it('denies a user from acting on an event that is not on their own calendar', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $other = User::factory()->forSchool($school)->director()->create();
    $calendar = Calendar::factory()->create(['school_id' => $school->id, 'user_id' => $owner->id]);
    $event = CalendarEvent::factory()->create(['school_id' => $school->id]);
    $calendar->events()->attach($event);

    expect($other->can('view', $event))->toBeFalse()
        ->and($other->can('update', $event))->toBeFalse()
        ->and($other->can('delete', $event))->toBeFalse();
});

it('never authorizes across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $ownerB = User::factory()->forSchool($schoolB)->teacher()->create();
    $calendarB = Calendar::factory()->create(['school_id' => $schoolB->id, 'user_id' => $ownerB->id]);
    $eventB = CalendarEvent::factory()->create(['school_id' => $schoolB->id]);
    $calendarB->events()->attach($eventB);

    $directorA = User::factory()->forSchool($schoolA)->director()->create();

    expect($directorA->can('view', $eventB))->toBeFalse();
});
