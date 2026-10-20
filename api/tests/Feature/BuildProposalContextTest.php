<?php

use App\Actions\AI\BuildProposalContext;
use App\Enums\AccommodationNarrativeStatus;
use App\Enums\AIProposalType;
use App\Models\Accommodation;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\GroupAccommodationNarrative;
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

function narrativeFor(array $scenario, AccommodationNarrativeStatus $status, string $content, ?string $publishedAt = null): void
{
    Tenancy::forSchool($scenario['school'], fn () => GroupAccommodationNarrative::create([
        'group_id' => $scenario['group']->id,
        'status' => $status,
        'content' => $content,
        'fingerprint' => str_repeat('0', 64),
        'generated_by_id' => $scenario['teacher']->id,
        'published_at' => $publishedAt,
    ]));
}

it('sends the last published group accommodation summary for class and assessment drafts', function () {
    $scenario = contextScopeScenario();
    narrativeFor($scenario, AccommodationNarrativeStatus::Archived, 'Resumen viejo', now()->subMonth()->toDateTimeString());
    narrativeFor($scenario, AccommodationNarrativeStatus::Published, 'Cinco alumnos tienen tiempo extendido.', now()->subDay()->toDateTimeString());
    narrativeFor($scenario, AccommodationNarrativeStatus::Draft, 'Borrador sin publicar');

    $class = contextFor($scenario, AIProposalType::ClassSession, ['focus' => 'fracciones']);
    $assessment = contextFor($scenario, AIProposalType::Assessment, [
        'subject_id' => $scenario['math']->id,
        'assessment_type' => 'written',
        'focus' => 'x',
    ]);
    $plan = contextFor($scenario, AIProposalType::AnnualPlan, ['subject_id' => $scenario['math']->id]);

    expect($class['group_accommodation_summary']['text'])->toBe('Cinco alumnos tienen tiempo extendido.')
        ->and($assessment['group_accommodation_summary']['text'])->toBe('Cinco alumnos tienen tiempo extendido.')
        ->and($plan['group_accommodation_summary'])->toBeNull()
        ->and(json_encode($class))->not->toContain('Borrador sin publicar');
});

it('generates without a group accommodation summary when none was published', function () {
    $scenario = contextScopeScenario();
    narrativeFor($scenario, AccommodationNarrativeStatus::Draft, 'Borrador sin publicar');

    $context = contextFor($scenario, AIProposalType::ClassSession, ['focus' => 'fracciones']);

    expect($context['group_accommodation_summary'])->toBeNull();
});

it('sends the recent performance of the group in the subject as aggregates only', function () {
    $scenario = contextScopeScenario();
    $make = fn (Subject $subject, string $date, array $scores) => tap(
        Assessment::factory()->create([
            'school_id' => $scenario['school']->id,
            'group_id' => $scenario['group']->id,
            'subject_id' => $subject->id,
            'teacher_id' => $scenario['teacher']->id,
            'type' => 'written',
            'administered_at' => $date,
        ]),
        function (Assessment $assessment) use ($scores, $scenario): void {
            foreach ($scores as $score) {
                AssessmentResult::factory()->create([
                    'assessment_id' => $assessment->id,
                    'student_id' => Student::factory()->create([
                        'school_id' => $scenario['school']->id,
                        'full_name' => 'Secret Performance Name',
                    ])->id,
                    'score' => $score,
                ]);
            }
        },
    );
    $make($scenario['math'], now()->subWeeks(2)->toDateString(), [4, 6, 8]);
    $make($scenario['math'], now()->subWeek()->toDateString(), [3, 5]);
    $make($scenario['science'], now()->subDays(2)->toDateString(), [12]);

    $context = contextFor($scenario, AIProposalType::Assessment, [
        'subject_id' => $scenario['math']->id,
        'assessment_type' => 'written',
        'focus' => 'x',
    ]);

    expect($context['recent_performance'])->toHaveCount(2)
        ->and($context['recent_performance'][0])->toMatchArray([
            'results_count' => 2,
            'average_score' => 4.0,
            'lowest_score' => 3.0,
            'highest_score' => 5.0,
        ])
        ->and($context['recent_performance'][1]['average_score'])->toBe(6.0)
        ->and(json_encode($context))->not->toContain('Secret Performance Name');
});
