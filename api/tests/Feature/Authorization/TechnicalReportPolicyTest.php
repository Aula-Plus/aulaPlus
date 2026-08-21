<?php

use App\Models\School;
use App\Models\Student;
use App\Models\TechnicalReport;
use App\Models\User;

// Specific case required by docs/prompts/02-roles-permisos.md §4: only a
// psychopedagogue can create a TechnicalReport — director, who has broad
// access to nearly everything else in this matrix, explicitly cannot.
it('lets only a psychopedagogue create a technical report — not even a director', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();

    expect($psychopedagogue->can('create', TechnicalReport::class))->toBeTrue()
        ->and($director->can('create', TechnicalReport::class))->toBeFalse()
        ->and($teacher->can('create', TechnicalReport::class))->toBeFalse();
});

it('lets director and psychopedagogue view technical reports, but not a teacher', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $report = TechnicalReport::factory()->create(['student_id' => $student->id]);

    expect($director->can('view', $report))->toBeTrue()
        ->and($psychopedagogue->can('view', $report))->toBeTrue()
        ->and($teacher->can('view', $report))->toBeFalse();
});

it('never authorizes across schools even for a psychopedagogue', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $psychopedagogueA = User::factory()->forSchool($schoolA)->psychopedagogue()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
    $reportB = TechnicalReport::factory()->create(['student_id' => $studentB->id]);

    expect($psychopedagogueA->can('view', $reportB))->toBeFalse();
});
