<?php

use App\Models\Assessment;
use App\Models\Group;
use App\Models\School;
use App\Models\User;

it('lets the owning teacher view, update and delete their assessment', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id]);

    expect($teacher->can('view', $assessment))->toBeTrue()
        ->and($teacher->can('create', Assessment::class))->toBeTrue()
        ->and($teacher->can('update', $assessment))->toBeTrue()
        ->and($teacher->can('delete', $assessment))->toBeTrue();
});

it('lets director and psychopedagogue view but never write another teacher\'s assessment', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $owner->id]);

    expect($director->can('view', $assessment))->toBeTrue()
        ->and($director->can('update', $assessment))->toBeFalse()
        ->and($psychopedagogue->can('view', $assessment))->toBeTrue()
        ->and($psychopedagogue->can('update', $assessment))->toBeFalse();
});

it('denies a teacher from viewing or writing another teacher\'s assessment', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $owner->id]);

    expect($otherTeacher->can('view', $assessment))->toBeFalse()
        ->and($otherTeacher->can('update', $assessment))->toBeFalse()
        ->and($otherTeacher->can('delete', $assessment))->toBeFalse();
});

it('never authorizes across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $ownerB = User::factory()->forSchool($schoolB)->teacher()->create();
    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);
    $assessmentB = Assessment::factory()->create(['group_id' => $groupB->id, 'teacher_id' => $ownerB->id]);

    expect($directorA->can('view', $assessmentB))->toBeFalse();
});
