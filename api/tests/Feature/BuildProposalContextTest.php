<?php

use App\Actions\AI\BuildProposalContext;
use App\Enums\AIProposalType;
use App\Models\Accommodation;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
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

/**
 * A group with one student and accommodations that differ in one thing each,
 * labelled by focus_area so the assertions read what reached the model.
 */
function contextScopeScenario(): array
{
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $math = Subject::factory()->create(['school_id' => $school->id]);
    $science = Subject::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher, $math);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $group->students()->attach($student, ['school_year' => now()->year]);

    $make = fn (string $focusArea, array $attributes = []) => Accommodation::factory()->create([
        'student_id' => $student->id,
        'focus_area' => $focusArea,
        'starts_on' => now()->subMonth()->toDateString(),
        ...$attributes,
    ]);

    $make('general');
    $make('pending', ['requires_external_approval' => true, 'approved' => null]);
    $make('rejected', ['requires_external_approval' => true, 'approved' => false]);
    $make('approved', ['requires_external_approval' => true, 'approved' => true]);
    $make('inactive', ['active' => false]);
    $make('future', ['starts_on' => now()->addWeek()->toDateString()]);
    $make('math')->subjects()->attach($math);
    $make('science')->subjects()->attach($science);
    $make('written', ['assessment_types' => ['written']]);

    return compact('school', 'teacher', 'group', 'math', 'science', 'student');
}

function contextFor(array $scenario, AIProposalType $type, array $parameters): array
{
    $proposal = AIProposal::factory()->create([
        'group_id' => $scenario['group']->id,
        'requested_by_id' => $scenario['teacher']->id,
        'type' => $type,
        'input_parameters' => $parameters,
    ]);

    return Tenancy::forSchool($scenario['school'], fn () => (new BuildProposalContext)($proposal));
}

function focusAreas(array $context): array
{
    return collect($context['student_cohort_summary']['active_accommodations_by_focus_area'])
        ->keys()->sort()->values()->all();
}

it('only sends accommodations in force: active, approved when required and already started', function () {
    $scenario = contextScopeScenario();

    $context = contextFor($scenario, AIProposalType::ClassSession, ['focus' => 'fracciones']);

    // No subject in the request: every subject's accommodations count.
    expect(focusAreas($context))->toBe(['approved', 'general', 'math', 'science', 'written']);
});

it('only sends the general accommodations and those of the requested subject', function () {
    $scenario = contextScopeScenario();

    $context = contextFor($scenario, AIProposalType::AnnualPlan, [
        'subject_id' => $scenario['math']->id,
        'student_id' => $scenario['student']->id,
    ]);

    expect(focusAreas($context))->toBe(['approved', 'general', 'math', 'written'])
        ->and(collect($context['target_student']['active_accommodations'])->pluck('focus_area')->sort()->values()->all())
        ->toBe(['approved', 'general', 'math', 'written']);
});

it('takes the subject of the annual plan a unit belongs to', function () {
    $scenario = contextScopeScenario();
    $plan = AnnualPlan::factory()->create([
        'school_id' => $scenario['school']->id,
        'group_id' => $scenario['group']->id,
        'subject_id' => $scenario['science']->id,
        'teacher_id' => $scenario['teacher']->id,
    ]);

    $context = contextFor($scenario, AIProposalType::Unit, ['annual_plan_id' => $plan->id, 'focus' => 'x']);

    expect(focusAreas($context))->toBe(['approved', 'general', 'science', 'written']);
});

it('only sends the accommodations that cover the requested assessment type', function () {
    $scenario = contextScopeScenario();

    $oral = contextFor($scenario, AIProposalType::Assessment, [
        'subject_id' => $scenario['math']->id,
        'assessment_type' => 'oral',
        'focus' => 'x',
    ]);
    $written = contextFor($scenario, AIProposalType::Assessment, [
        'subject_id' => $scenario['math']->id,
        'assessment_type' => 'written',
        'focus' => 'x',
    ]);

    expect(focusAreas($oral))->toBe(['approved', 'general', 'math'])
        ->and(focusAreas($written))->toBe(['approved', 'general', 'math', 'written']);
});

it('sends no past results of other units', function () {
    // PO, ClickUp 86e3jpzdr (latest decision): the AI no longer receives the
    // narrative psychopedagogy publishes — it states how many students have
    // each accommodation, which identifies someone in a small group. Group
    // performance may only come from assessments of the unit being planned,
    // and assessments are not tied to a unit yet, so none qualifies.
    $scenario = contextScopeScenario();
    $assessment = Assessment::factory()->create([
        'school_id' => $scenario['school']->id,
        'group_id' => $scenario['group']->id,
        'subject_id' => $scenario['math']->id,
        'teacher_id' => $scenario['teacher']->id,
        'type' => 'written',
        'administered_at' => now()->subWeek()->toDateString(),
    ]);
    AssessmentResult::factory()->create([
        'assessment_id' => $assessment->id,
        'student_id' => $scenario['student']->id,
        'score' => 3.75,
    ]);

    foreach ([
        contextFor($scenario, AIProposalType::ClassSession, ['focus' => 'fracciones']),
        contextFor($scenario, AIProposalType::Assessment, [
            'subject_id' => $scenario['math']->id,
            'assessment_type' => 'written',
            'focus' => 'x',
        ]),
    ] as $context) {
        $sent = json_encode($context);

        expect($context)->not->toHaveKeys(['group_accommodation_summary', 'recent_performance'])
            ->and($sent)->not->toContain('3.75');
    }
});
