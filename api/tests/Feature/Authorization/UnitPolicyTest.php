<?php

use App\Models\AnnualPlan;
use App\Models\Group;
use App\Models\School;
use App\Models\Unit;
use App\Models\User;

it('lets the owning teacher (via their annual plan) view, update and delete a unit', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $plan = AnnualPlan::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id]);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);

    expect($teacher->can('view', $unit))->toBeTrue()
        ->and($teacher->can('create', Unit::class))->toBeTrue()
        ->and($teacher->can('update', $unit))->toBeTrue()
        ->and($teacher->can('delete', $unit))->toBeTrue();
});

it('lets director and psychopedagogue view but never write another teacher\'s unit', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $plan = AnnualPlan::factory()->create(['group_id' => $group->id, 'teacher_id' => $owner->id]);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);

    expect($director->can('view', $unit))->toBeTrue()
        ->and($director->can('update', $unit))->toBeFalse()
        ->and($psychopedagogue->can('view', $unit))->toBeTrue()
        ->and($psychopedagogue->can('update', $unit))->toBeFalse();
});

it('denies a teacher from viewing or writing another teacher\'s unit', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $plan = AnnualPlan::factory()->create(['group_id' => $group->id, 'teacher_id' => $owner->id]);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);

    expect($otherTeacher->can('view', $unit))->toBeFalse()
        ->and($otherTeacher->can('update', $unit))->toBeFalse();
});

it('never authorizes across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $ownerB = User::factory()->forSchool($schoolB)->teacher()->create();
    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);
    $planB = AnnualPlan::factory()->create(['group_id' => $groupB->id, 'teacher_id' => $ownerB->id]);
    $unitB = Unit::factory()->create(['annual_plan_id' => $planB->id]);

    expect($directorA->can('view', $unitB))->toBeFalse();
});
