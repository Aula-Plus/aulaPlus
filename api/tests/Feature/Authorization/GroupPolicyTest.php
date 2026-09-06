<?php

use App\Models\Group;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy;

afterEach(fn () => Tenancy::forget());

it('lets a director view any group in their school', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    expect($director->can('view', $group))->toBeTrue()
        ->and($director->can('create', Group::class))->toBeTrue();
});

it('lets a psychopedagogue view any group in their school but not create one', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    expect($psychopedagogue->can('view', $group))->toBeTrue()
        ->and($psychopedagogue->can('create', Group::class))->toBeFalse();
});

it('lets a teacher view groups they lead, but not update or delete them', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();

    $own = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    $other = Group::factory()->create(['school_id' => $school->id]);

    expect($teacher->can('view', $own))->toBeTrue()
        ->and($teacher->can('view', $other))->toBeFalse()
        ->and($teacher->can('update', $own))->toBeFalse()
        ->and($teacher->can('delete', $own))->toBeFalse();
});

it('never authorizes across schools even for a director', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);

    expect($directorA->can('view', $groupB))->toBeFalse()
        ->and($directorA->can('update', $groupB))->toBeFalse()
        ->and($directorA->can('delete', $groupB))->toBeFalse();
});
