<?php

use App\Models\School;
use App\Models\User;

it('lets a director view, create and update users in their school', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();

    expect($director->can('view', $teacher))->toBeTrue()
        ->and($director->can('create', User::class))->toBeTrue()
        ->and($director->can('update', $teacher))->toBeTrue();
});

it('denies a teacher or psychopedagogue from managing users', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $other = User::factory()->forSchool($school)->teacher()->create();

    expect($teacher->can('view', $other))->toBeFalse()
        ->and($teacher->can('create', User::class))->toBeFalse()
        ->and($teacher->can('update', $other))->toBeFalse()
        ->and($psychopedagogue->can('view', $other))->toBeFalse()
        ->and($psychopedagogue->can('create', User::class))->toBeFalse()
        ->and($psychopedagogue->can('update', $other))->toBeFalse();
});

it('never authorizes across schools even for a director', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $userB = User::factory()->forSchool($schoolB)->teacher()->create();

    expect($directorA->can('view', $userB))->toBeFalse()
        ->and($directorA->can('update', $userB))->toBeFalse();
});
