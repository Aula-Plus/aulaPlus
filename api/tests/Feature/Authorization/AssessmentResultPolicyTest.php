<?php

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\School;
use App\Models\User;

/**
 * AssessmentResultPolicy authorizes relative to the parent Assessment (results
 * have no independent owner) — docs/prompts/13-evaluaciones-resultados.md §2.
 */
it('lets the owning teacher view, create and update results', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id]);

    expect($teacher->can('view', [AssessmentResult::class, $assessment]))->toBeTrue()
        ->and($teacher->can('create', [AssessmentResult::class, $assessment]))->toBeTrue()
        ->and($teacher->can('update', [AssessmentResult::class, $assessment]))->toBeTrue();
});

it('lets director and psychopedagogue view results but never write them', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $owner->id]);

    expect($director->can('view', [AssessmentResult::class, $assessment]))->toBeTrue()
        ->and($director->can('create', [AssessmentResult::class, $assessment]))->toBeFalse()
        ->and($psychopedagogue->can('view', [AssessmentResult::class, $assessment]))->toBeTrue()
        ->and($psychopedagogue->can('update', [AssessmentResult::class, $assessment]))->toBeFalse();
});

it('denies a non-owner teacher from viewing or writing results', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $other = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $owner->id]);

    expect($other->can('view', [AssessmentResult::class, $assessment]))->toBeFalse()
        ->and($other->can('create', [AssessmentResult::class, $assessment]))->toBeFalse();
});

it('never authorizes results across schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $ownerB = User::factory()->forSchool($schoolB)->teacher()->create();
    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);
    $assessmentB = Assessment::factory()->create(['group_id' => $groupB->id, 'teacher_id' => $ownerB->id]);

    expect($directorA->can('view', [AssessmentResult::class, $assessmentB]))->toBeFalse();
});
