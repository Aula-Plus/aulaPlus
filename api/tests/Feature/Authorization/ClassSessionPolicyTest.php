<?php

use App\Models\ClassSession;
use App\Models\Group;
use App\Models\School;
use App\Models\User;

it('lets the teacher who leads the group view, update and delete its class sessions', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $classSession = ClassSession::factory()->create(['group_id' => $group->id]);

    expect($teacher->can('view', $classSession))->toBeTrue()
        ->and($teacher->can('create', ClassSession::class))->toBeTrue()
        ->and($teacher->can('update', $classSession))->toBeTrue()
        ->and($teacher->can('delete', $classSession))->toBeTrue();
});

it('lets director and psychopedagogue view but never write a class session of a group they don\'t lead', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($owner);
    $classSession = ClassSession::factory()->create(['group_id' => $group->id]);

    expect($director->can('view', $classSession))->toBeTrue()
        ->and($director->can('update', $classSession))->toBeFalse()
        ->and($psychopedagogue->can('view', $classSession))->toBeTrue()
        ->and($psychopedagogue->can('update', $classSession))->toBeFalse();
});

it('denies a teacher who doesn\'t lead the group', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($owner);
    $classSession = ClassSession::factory()->create(['group_id' => $group->id]);

    expect($otherTeacher->can('view', $classSession))->toBeFalse()
        ->and($otherTeacher->can('update', $classSession))->toBeFalse();
});

it('never authorizes across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $ownerB = User::factory()->forSchool($schoolB)->teacher()->create();
    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);
    $groupB->teachers()->attach($ownerB);
    $classSessionB = ClassSession::factory()->create(['group_id' => $groupB->id]);

    expect($directorA->can('view', $classSessionB))->toBeFalse();
});
