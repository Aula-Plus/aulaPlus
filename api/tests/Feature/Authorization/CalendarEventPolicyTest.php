<?php

use App\Models\Calendar;
use App\Models\CalendarEvent;
use App\Models\School;
use App\Models\User;

/**
 * Screen 21 (ClickUp 1dfd3mc4bgh): institutional events are seen by the whole
 * school and managed by Direction only; any other stored date (a meeting) is
 * seen and managed only by the people whose calendar it is linked to.
 */
it('lets a user view, update and delete a meeting on their own calendar', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $calendar = Calendar::factory()->create(['school_id' => $school->id, 'user_id' => $teacher->id]);
    $event = CalendarEvent::factory()->meeting()->create(['school_id' => $school->id]);
    $calendar->events()->attach($event);

    expect($teacher->can('view', $event))->toBeTrue()
        ->and($teacher->can('update', $event))->toBeTrue()
        ->and($teacher->can('delete', $event))->toBeTrue();
});

it('denies everyone else a meeting that is not on their own calendar, direction included', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $other = User::factory()->forSchool($school)->director()->create();
    $calendar = Calendar::factory()->create(['school_id' => $school->id, 'user_id' => $owner->id]);
    $event = CalendarEvent::factory()->meeting()->create(['school_id' => $school->id]);
    $calendar->events()->attach($event);

    expect($other->can('view', $event))->toBeFalse()
        ->and($other->can('update', $event))->toBeFalse()
        ->and($other->can('delete', $event))->toBeFalse();
});

it('lets everyone see an institutional event and only direction create or manage one', function () {
    $school = School::factory()->create();
    $event = CalendarEvent::factory()->create(['school_id' => $school->id]);
    $director = User::factory()->forSchool($school)->director()->create();

    expect($director->can('create', CalendarEvent::class))->toBeTrue()
        ->and($director->can('update', $event))->toBeTrue()
        ->and($director->can('delete', $event))->toBeTrue();

    foreach (['teacher', 'psychopedagogue'] as $role) {
        $user = User::factory()->forSchool($school)->{$role}()->create();
        expect($user->can('view', $event))->toBeTrue()
            ->and($user->can('create', CalendarEvent::class))->toBeFalse()
            ->and($user->can('update', $event))->toBeFalse()
            ->and($user->can('delete', $event))->toBeFalse();
    }
});

it('never authorizes across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $ownerB = User::factory()->forSchool($schoolB)->teacher()->create();
    $calendarB = Calendar::factory()->create(['school_id' => $schoolB->id, 'user_id' => $ownerB->id]);
    $eventB = CalendarEvent::factory()->meeting()->create(['school_id' => $schoolB->id]);
    $calendarB->events()->attach($eventB);
    $institutionalB = CalendarEvent::factory()->create(['school_id' => $schoolB->id]);

    $directorA = User::factory()->forSchool($schoolA)->director()->create();

    expect($directorA->can('view', $eventB))->toBeFalse()
        ->and($directorA->can('view', $institutionalB))->toBeFalse()
        ->and($directorA->can('update', $institutionalB))->toBeFalse()
        ->and($directorA->can('delete', $institutionalB))->toBeFalse();
});
