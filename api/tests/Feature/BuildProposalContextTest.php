<?php

use App\Actions\AI\BuildProposalContext;
use App\Enums\AIProposalType;
use App\Models\Accommodation;
use App\Models\AIProposal;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Builds the context for a proposal whose group has students with active
 * accommodations, then returns [context, group, students] for the assertions.
 */
function buildContextForGroupWithStudents(array $overrides = []): array
{
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create([
        'school_id' => $school->id,
        'group_profile' => ['focus' => 'convivencia'],
    ]);

    $studentA = Student::factory()->create([
        'school_id' => $school->id,
        'full_name' => 'Juanita Distinctive Testname',
        'has_therapeutic_companion' => true,
    ]);
    $studentB = Student::factory()->create([
        'school_id' => $school->id,
        'full_name' => 'Pedro Otro Apellido',
    ]);
    $group->students()->attach($studentA, ['school_year' => now()->year]);
    $group->students()->attach($studentB, ['school_year' => now()->year]);

    Accommodation::factory()->create([
        'student_id' => $studentA->id,
        'school_id' => $school->id,
        'focus_area' => 'literacy',
        'active' => true,
    ]);
    Accommodation::factory()->create([
        'student_id' => $studentA->id,
        'school_id' => $school->id,
        'focus_area' => 'literacy',
        'active' => false,
    ]);

    $proposal = AIProposal::factory()->create(array_merge([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
        'type' => AIProposalType::Unit,
        'input_parameters' => ['focus' => 'fracciones'],
    ], $overrides));

    $context = Tenancy::forSchool($school, fn () => (new BuildProposalContext)($proposal));

    return [$context, $studentA, $studentB];
}

it('never sends any student full_name in the built context', function () {
    [$context, $studentA, $studentB] = buildContextForGroupWithStudents();

    $encoded = json_encode($context);

    expect($encoded)->not->toContain($studentA->full_name);
    expect($encoded)->not->toContain($studentB->full_name);
    expect($encoded)->not->toContain('Distinctive');
});

it('aggregates the student cohort by active accommodations and therapeutic companion', function () {
    [$context] = buildContextForGroupWithStudents();

    $summary = $context['student_cohort_summary'];

    expect($summary['total_students'])->toBe(2)
        ->and($summary['with_therapeutic_companion'])->toBe(1)
        ->and($summary['with_active_accommodations'])->toBe(1)
        // Only the active accommodation counts, not the inactive one.
        ->and($summary['active_accommodations_by_focus_area']['literacy'])->toBe(1);
});

it('references a personalised target student by an opaque id, never by name', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'full_name' => 'Target Secret Name',
        'learning_profile' => ['reading' => 'needs support'],
    ]);
    $group->students()->attach($student, ['school_year' => now()->year]);

    $proposal = AIProposal::factory()->create([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
        'type' => AIProposalType::AnnualPlan,
        'input_parameters' => ['student_id' => $student->id],
    ]);

    $context = Tenancy::forSchool($school, fn () => (new BuildProposalContext)($proposal));

    expect($context['target_student']['ref'])->toBe('Student #1')
        ->and($context['target_student']['learning_profile'])->toBe(['reading' => 'needs support'])
        ->and(json_encode($context))->not->toContain('Target Secret Name');
});
