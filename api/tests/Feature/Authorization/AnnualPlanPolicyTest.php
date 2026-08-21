<?php

use App\Models\AnnualPlan;
use App\Models\Group;
use App\Models\School;
use App\Models\User;

it('lets the owning teacher view, update and delete their annual plan', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $plan = AnnualPlan::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id]);

    expect($teacher->can('view', $plan))->toBeTrue()
        ->and($teacher->can('create', AnnualPlan::class))->toBeTrue()
        ->and($teacher->can('update', $plan))->toBeTrue()
        ->and($teacher->can('delete', $plan))->toBeTrue();
});

it('lets director and psychopedagogue view but never write another teacher\'s plan', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $plan = AnnualPlan::factory()->create(['group_id' => $group->id, 'teacher_id' => $owner->id]);

    expect($director->can('view', $plan))->toBeTrue()
        ->and($director->can('update', $plan))->toBeFalse()
        ->and($director->can('delete', $plan))->toBeFalse()
        ->and($psychopedagogue->can('view', $plan))->toBeTrue()
        ->and($psychopedagogue->can('update', $plan))->toBeFalse();
});

it('denies a teacher from viewing or writing another teacher\'s plan', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $plan = AnnualPlan::factory()->create(['group_id' => $group->id, 'teacher_id' => $owner->id]);

    expect($otherTeacher->can('view', $plan))->toBeFalse()
        ->and($otherTeacher->can('update', $plan))->toBeFalse()
        ->and($otherTeacher->can('delete', $plan))->toBeFalse();
});

it('never authorizes across schools even for the owning teacher\'s director', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $ownerB = User::factory()->forSchool($schoolB)->teacher()->create();
    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);
    $planB = AnnualPlan::factory()->create(['group_id' => $groupB->id, 'teacher_id' => $ownerB->id]);

    expect($directorA->can('view', $planB))->toBeFalse()
        ->and($ownerB->can('view', $planB))->toBeTrue();
});
