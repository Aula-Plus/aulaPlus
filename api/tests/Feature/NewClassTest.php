<?php

use App\Actions\AI\ApplyProposal;
use App\Actions\AI\BuildProposalContext;
use App\Enums\AIProposalStatus;
use App\Enums\AIProposalType;
use App\Enums\ClassSessionStatus;
use App\Enums\GradesVisibility;
use App\Jobs\GenerateAIProposalJob;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\UnitObjective;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Screens 14 «Nueva clase» and 15 «Propuesta de clase» (ClickUp 86e3jujh0):
 * asking the AI for a class, deciding on each section, confirming it as a
 * real ClassSession and closing it in one tap.
 */

function classObjective(Unit $unit, string $description = 'Objetivo', int $position = 1): UnitObjective
{
    $objective = new UnitObjective;
    $objective->forceFill([
        'school_id' => $unit->annualPlan->school_id,
        'unit_id' => $unit->id,
        'description' => $description,
        'position' => $position,
    ])->save();

    return $objective;
}

/**
 * A math teacher of 2°EMS-A with their annual plan, a unit and two objectives.
 *
 * @return array{school: School, teacher: User, group: Group, subject: Subject, plan: AnnualPlan, unit: Unit, objectives: list<UnitObjective>}
 */
function mathClassSetup(): array
{
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => '2°EMS-A']);
    $subject = leadGroup($group, $teacher, Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']));
    $plan = AnnualPlan::factory()->create([
        'school_id' => $school->id,
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'teacher_id' => $teacher->id,
    ]);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id, 'name' => 'Fracciones']);
    $objectives = [
        classObjective($unit, 'Que comparen fracciones con material concreto', 1),
        classObjective($unit, 'Que ordenen fracciones', 2),
    ];

    return compact('school', 'teacher', 'group', 'subject', 'plan', 'unit', 'objectives');
}

/**
 * @return array<string, mixed>
 */
function sectionsDraft(string $closing = 'Puesta en común.'): array
{
    return [
        'title' => 'Comparar fracciones',
        'duration_minutes' => 45,
        'sections' => [
            'opening' => ['duration_minutes' => 10, 'content' => 'Tiras de papel para doblar.'],
            'central_activity' => ['duration_minutes' => 25, 'content' => 'En parejas comparan fracciones con las tiras.'],
            'closing' => ['duration_minutes' => 10, 'content' => $closing],
            'materials' => ['content' => 'Tiras de papel, tijeras.'],
            'assessment' => ['content' => 'Pregunta de salida.'],
        ],
        'rationale' => 'Propuse material concreto porque lo pidió el docente. No leí un planificador.',
        'data_used' => [['data' => 'Promedio 5,8', 'source' => 'Escrito del 15/06', 'reason' => 'Le costó comparar']],
    ];
}

/**
 * @param  array<string, mixed>  $draft
 */
function anthropicReply(array $draft): array
{
    return ['content' => [['type' => 'text', 'text' => json_encode($draft)]]];
}

/**
 * @param  array<string, string>  $decisions
 * @return array<string, mixed>
 */
function sectionDecisions(array $decisions = []): array
{
    $body = [];
    foreach (['opening', 'central_activity', 'closing', 'materials', 'assessment'] as $key) {
        $body[$key] = ['decision' => $decisions[$key] ?? 'approved'];
    }

    return ['sections' => $body];
}

function completedClassProposal(array $setup, array $parameters = []): AIProposal
{
    return AIProposal::factory()->completed(sectionsDraft())->create([
        'school_id' => $setup['school']->id,
        'group_id' => $setup['group']->id,
        'requested_by_id' => $setup['teacher']->id,
        'type' => AIProposalType::ClassSession,
        'input_parameters' => array_merge([
            'unit_id' => $setup['unit']->id,
            'unit_objective_ids' => [$setup['objectives'][0]->id],
            'focus' => 'Fracciones',
            'objectives' => 'Que comparen fracciones con material concreto',
            'date' => '2026-11-20',
            'duration_minutes' => 45,
        ], $parameters),
    ]);
}

// ── Requesting a class (screen 14) ──────────────────────────────────────────

it('lets the teacher ask for a class from a unit of their annual plan, with chosen objectives', function () {
    Queue::fake();
    $s = mathClassSetup();
    Sanctum::actingAs($s['teacher']);

    $this->postJson("/api/v1/groups/{$s['group']->id}/assistant/generate", [
        'type' => 'class_session',
        'parameters' => [
            'unit_id' => $s['unit']->id,
            'unit_objective_ids' => [$s['objectives'][0]->id],
            'focus' => 'Fracciones',
            'objectives' => 'Que comparen fracciones con material concreto',
            'date' => '2026-11-20',
            'duration_minutes' => 45,
        ],
    ])->assertStatus(202)
        ->assertJsonPath('data.class_request.group.name', '2°EMS-A')
        ->assertJsonPath('data.class_request.subject.name', 'Matemática')
        ->assertJsonPath('data.class_request.unit.name', 'Fracciones')
        ->assertJsonPath('data.class_request.unit_objectives.0.description', 'Que comparen fracciones con material concreto')
        ->assertJsonPath('data.can_decide', true);

    Queue::assertPushed(GenerateAIProposalJob::class);
});

it('lets the teacher ask for a free class entering directly with group and subject', function () {
    Queue::fake();
    $s = mathClassSetup();
    Sanctum::actingAs($s['teacher']);

    $this->postJson("/api/v1/groups/{$s['group']->id}/assistant/generate", [
        'type' => 'class_session',
        'parameters' => ['subject_id' => $s['subject']->id, 'focus' => 'Fracciones'],
    ])->assertStatus(202)
        ->assertJsonPath('data.class_request.unit', null)
        ->assertJsonPath('data.class_request.subject.name', 'Matemática');
});

it('never queues a class request without a subject', function () {
    Queue::fake();
    $s = mathClassSetup();
    Sanctum::actingAs($s['teacher']);

    $this->postJson("/api/v1/groups/{$s['group']->id}/assistant/generate", [
        'type' => 'class_session',
        'parameters' => ['focus' => 'Fracciones', 'objectives' => 'Que comparen'],
    ])->assertStatus(422)->assertJsonValidationErrors(['parameters.subject_id', 'parameters.unit_id']);

    Queue::assertNothingPushed();
});

it('rejects class requests outside what the teacher teaches', function () {
    Queue::fake();
    $s = mathClassSetup();
    $url = "/api/v1/groups/{$s['group']->id}/assistant/generate";

    // A colleague's subject in the same group, and a unit of that subject.
    $colleague = User::factory()->forSchool($s['school'])->teacher()->create();
    $history = leadGroup($s['group'], $colleague);
    $historyPlan = AnnualPlan::factory()->create([
        'school_id' => $s['school']->id, 'group_id' => $s['group']->id,
        'subject_id' => $history->id, 'teacher_id' => $colleague->id,
    ]);
    $historyUnit = Unit::factory()->create(['annual_plan_id' => $historyPlan->id]);
    $otherObjective = classObjective($historyUnit);

    Sanctum::actingAs($s['teacher']);
    $this->postJson($url, ['type' => 'class_session', 'parameters' => ['subject_id' => $history->id, 'focus' => 'x']])
        ->assertStatus(422)->assertJsonValidationErrors('parameters.subject_id');
    $this->postJson($url, ['type' => 'class_session', 'parameters' => ['unit_id' => $historyUnit->id, 'focus' => 'x']])
        ->assertStatus(422)->assertJsonValidationErrors('parameters.unit_id');
    // An objective of another unit.
    $this->postJson($url, ['type' => 'class_session', 'parameters' => [
        'unit_id' => $s['unit']->id, 'unit_objective_ids' => [$otherObjective->id], 'focus' => 'x',
    ]])->assertStatus(422)->assertJsonValidationErrors('parameters.unit_objective_ids.0');

    Queue::assertNothingPushed();
});

it('forbids a teacher of another group, another school, psychopedagogy and direction from asking for a class', function () {
    Queue::fake();
    $s = mathClassSetup();
    $url = "/api/v1/groups/{$s['group']->id}/assistant/generate";
    $body = ['type' => 'class_session', 'parameters' => ['subject_id' => $s['subject']->id, 'focus' => 'x']];

    $outsider = User::factory()->forSchool($s['school'])->teacher()->create();
    leadGroup(Group::factory()->create(['school_id' => $s['school']->id]), $outsider);
    Sanctum::actingAs($outsider);
    $this->postJson($url, $body)->assertForbidden();

    Sanctum::actingAs(User::factory()->forSchool($s['school'])->psychopedagogue()->create());
    $this->postJson($url, $body)->assertForbidden();

    Sanctum::actingAs(User::factory()->forSchool($s['school'])->director()->create());
    $this->postJson($url, $body)->assertForbidden();

    $otherSchool = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($otherSchool)->teacher()->create());
    expect($this->postJson($url, $body)->status())->toBeIn([403, 404]);

    Queue::assertNothingPushed();
});

it('only regenerates from an earlier class draft of the same teacher', function () {
    Queue::fake();
    $s = mathClassSetup();
    $mine = completedClassProposal($s);
    $colleague = User::factory()->forSchool($s['school'])->teacher()->create();
    leadGroup($s['group'], $colleague, $s['subject']);
    $url = "/api/v1/groups/{$s['group']->id}/assistant/generate";
    $body = fn (AIProposal $reference) => ['type' => 'class_session', 'parameters' => [
        'unit_id' => $s['unit']->id, 'focus' => 'Fracciones',
        'reference_proposal_id' => $reference->id,
        'regenerate_sections' => ['closing'],
        'change_requests' => ['closing' => 'Más corto'],
    ]];

    Sanctum::actingAs($colleague);
    $this->postJson($url, $body($mine))->assertStatus(422)->assertJsonValidationErrors('parameters.reference_proposal_id');

    Sanctum::actingAs($s['teacher']);
    $this->postJson($url, $body($mine))->assertStatus(202);
});

// ── What the AI receives ────────────────────────────────────────────────────

it('sends the unit, its chosen objectives, the group performance from any unit, and says no planner was read', function () {
    $s = mathClassSetup();
    $students = Student::factory()->count(3)->create(['school_id' => $s['school']->id, 'full_name' => 'Ana Pérez Secreta']);
    foreach ($students as $student) {
        $student->groups()->attach($s['group'], ['school_year' => now()->year]);
    }
    // An assessment tied to no unit still reaches the AI (PA-116)…
    $loose = Assessment::factory()->create([
        'group_id' => $s['group']->id, 'subject_id' => $s['subject']->id,
        'purpose' => 'Escrito de junio', 'administered_at' => '2026-06-15',
    ]);
    foreach ($students as $i => $student) {
        AssessmentResult::factory()->create(['assessment_id' => $loose->id, 'student_id' => $student->id, 'score' => 5 + $i]);
    }
    // …but one with fewer than three results does not (near-individual).
    $tiny = Assessment::factory()->create(['group_id' => $s['group']->id, 'subject_id' => $s['subject']->id, 'purpose' => 'Oral corto']);
    AssessmentResult::factory()->create(['assessment_id' => $tiny->id, 'student_id' => $students[0]->id, 'score' => 9]);

    $proposal = completedClassProposal($s);
    Tenancy::useSchool($s['school']);
    $context = app(BuildProposalContext::class)($proposal);

    expect($context['unit']['name'])->toBe('Fracciones')
        ->and($context['unit']['chosen_objectives'])->toBe(['Que comparen fracciones con material concreto'])
        ->and($context['unit_planner'])->toMatchArray(['read' => false, 'reason' => 'planner_not_available'])
        ->and($context['group_performance']['assessments'])->toHaveCount(1)
        ->and($context['group_performance']['assessments'][0])->toMatchArray([
            'title' => 'Escrito de junio', 'units' => [], 'average_score' => 6.0,
        ]);
    expect(json_encode($context))->not->toContain('Ana Pérez Secreta');
});

it('tells the AI a free class reads no planner', function () {
    $s = mathClassSetup();
    $proposal = completedClassProposal($s, ['unit_id' => null, 'unit_objective_ids' => null, 'subject_id' => $s['subject']->id]);
    Tenancy::useSchool($s['school']);

    $context = app(BuildProposalContext::class)($proposal);

    expect($context['unit'])->toBeNull()
        ->and($context['unit_planner'])->toMatchArray(['read' => false, 'reason' => 'class_without_unit']);
});

it('does not send another subject\'s averages to a teacher restricted to their own subject', function () {
    $s = mathClassSetup();
    $s['school']->update(['grades_visibility' => GradesVisibility::OwnSubject]);
    $other = Subject::factory()->create(['school_id' => $s['school']->id]);
    $assessment = Assessment::factory()->create(['group_id' => $s['group']->id, 'subject_id' => $other->id]);
    foreach (Student::factory()->count(3)->create(['school_id' => $s['school']->id]) as $student) {
        AssessmentResult::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 7]);
    }

    $proposal = completedClassProposal($s);
    Tenancy::useSchool($s['school']);

    expect(app(BuildProposalContext::class)($proposal)['group_performance']['assessments'])->toBe([]);
});

it('stores a class draft by sections with its data used and the planner note', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicReply(sectionsDraft()), 200)]);
    $s = mathClassSetup();
    $proposal = completedClassProposal($s);
    $proposal->update(['status' => AIProposalStatus::Pending, 'raw_response' => null]);

    GenerateAIProposalJob::dispatchSync($proposal);

    $raw = $proposal->refresh()->raw_response;
    expect($proposal->status)->toBe(AIProposalStatus::Completed)
        ->and(array_keys($raw['sections']))->toBe(['opening', 'central_activity', 'closing', 'materials', 'assessment'])
        ->and($raw['sections']['opening'])->toBe(['content' => 'Tiras de papel para doblar.', 'duration_minutes' => 10])
        ->and($raw['data_used'][0]['source'])->toBe('Escrito del 15/06')
        ->and($raw['planner'])->toBe(['read' => false, 'reason' => 'planner_not_available']);
    Http::assertSent(fn ($request) => str_contains($request['system'], '"central_activity"')
        && str_contains($request['system'], 'no leíste un planificador')
        && ! str_contains($request['messages'][0]['content'], 'x-api-key'));
});

it('marks a class draft missing a section as off-schema', function () {
    $draft = sectionsDraft();
    unset($draft['sections']['closing']);
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicReply($draft), 200)]);
    $s = mathClassSetup();
    $proposal = completedClassProposal($s);
    $proposal->update(['status' => AIProposalStatus::Pending, 'raw_response' => null]);

    GenerateAIProposalJob::dispatchSync($proposal);

    expect($proposal->refresh()->status)->toBe(AIProposalStatus::Error);
});

it('regenerates only the requested sections and keeps the others as they were', function () {
    $s = mathClassSetup();
    $reference = completedClassProposal($s);
    // The model rewrites everything; only the closing may change.
    $rewritten = sectionsDraft('Cierre nuevo con una pregunta.');
    $rewritten['sections']['opening']['content'] = 'Otra apertura';
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicReply($rewritten), 200)]);
    $retry = AIProposal::factory()->create([
        'school_id' => $s['school']->id, 'group_id' => $s['group']->id, 'requested_by_id' => $s['teacher']->id,
        'type' => AIProposalType::ClassSession,
        'input_parameters' => [...$reference->input_parameters, 'reference_proposal_id' => $reference->id,
            'regenerate_sections' => ['closing'], 'change_requests' => ['closing' => 'Con una pregunta']],
    ]);

    GenerateAIProposalJob::dispatchSync($retry);

    $raw = $retry->refresh()->raw_response;
    expect($raw['sections']['closing']['content'])->toBe('Cierre nuevo con una pregunta.')
        ->and($raw['sections']['opening']['content'])->toBe('Tiras de papel para doblar.')
        ->and($raw['regenerated_sections'])->toBe(['closing']);
});

// ── Confirming the class (screen 15) ────────────────────────────────────────

it('confirms a class with the approved and edited sections only, linked to the chosen objectives', function () {
    $s = mathClassSetup();
    $proposal = completedClassProposal($s);
    Sanctum::actingAs($s['teacher']);

    $body = sectionDecisions(['materials' => 'rejected']);
    $body['sections']['closing'] = ['decision' => 'edited', 'content' => 'Cierre mío', 'duration_minutes' => 5];

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply", $body)
        ->assertOk()->assertJsonPath('data.status', 'applied');

    $class = ClassSession::query()->sole();
    expect($class->group_id)->toBe($s['group']->id)
        ->and($class->subject_id)->toBe($s['subject']->id)
        ->and($class->unit_id)->toBe($s['unit']->id)
        ->and($class->date->toDateString())->toBe('2026-11-20')
        // The objective is the teacher's text, never the AI's.
        ->and($class->objective)->toBe('Que comparen fracciones con material concreto')
        ->and(collect($class->sections)->pluck('key')->all())->toBe(['opening', 'central_activity', 'closing', 'assessment'])
        ->and(collect($class->sections)->firstWhere('key', 'closing'))->toMatchArray(['content' => 'Cierre mío', 'duration_minutes' => 5, 'edited' => true])
        ->and($class->unitObjectives()->pluck('unit_objectives.id')->all())->toBe([$s['objectives'][0]->id]);

    // The chosen objective now counts as planned in the Programa anual.
    $objectives = collect($this->getJson("/api/v1/annual-plans/{$s['plan']->id}")->json('data.units.0.objectives'));
    expect($objectives->firstWhere('id', $s['objectives'][0]->id)['status'])->toBe('planned')
        ->and($objectives->firstWhere('id', $s['objectives'][1]->id)['status'])->toBe('unplanned');
});

it('saves nothing without a decision on every section, nor with every section rejected', function () {
    $s = mathClassSetup();
    $proposal = completedClassProposal($s);
    Sanctum::actingAs($s['teacher']);
    $url = "/api/v1/ai-proposals/{$proposal->id}/apply";

    $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('sections');
    $partial = sectionDecisions();
    unset($partial['sections']['closing']);
    $this->postJson($url, $partial)->assertStatus(422)->assertJsonValidationErrors('sections.closing');
    $this->postJson($url, sectionDecisions(array_fill_keys(['opening', 'central_activity', 'closing', 'materials', 'assessment'], 'rejected')))
        ->assertStatus(422)->assertJsonValidationErrors('sections');
    $this->postJson($url, sectionDecisions(['opening' => 'edited']))
        ->assertStatus(422)->assertJsonValidationErrors('sections.opening.content');

    expect(ClassSession::query()->count())->toBe(0)
        ->and($proposal->refresh()->status)->toBe(AIProposalStatus::Completed);
});

it('lets only the requester confirm; psychopedagogy reads the draft', function () {
    $s = mathClassSetup();
    $proposal = completedClassProposal($s);
    $psychopedagogue = User::factory()->forSchool($s['school'])->psychopedagogue()->create();
    $colleague = User::factory()->forSchool($s['school'])->teacher()->create();
    leadGroup($s['group'], $colleague, $s['subject']);

    Sanctum::actingAs($psychopedagogue);
    $this->getJson("/api/v1/ai-proposals/{$proposal->id}")->assertOk()->assertJsonPath('data.can_decide', false);
    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply", sectionDecisions())->assertForbidden();

    Sanctum::actingAs($colleague);
    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply", sectionDecisions())->assertForbidden();

    expect(ClassSession::query()->count())->toBe(0);
});

it('a confirmed free class adds nothing to the program coverage', function () {
    $s = mathClassSetup();
    $proposal = completedClassProposal($s, ['unit_id' => null, 'unit_objective_ids' => null, 'subject_id' => $s['subject']->id]);
    Sanctum::actingAs($s['teacher']);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply", sectionDecisions())->assertOk();

    $class = ClassSession::query()->sole();
    expect($class->unit_id)->toBeNull()->and($class->subject_id)->toBe($s['subject']->id);
    $this->getJson("/api/v1/annual-plans/{$s['plan']->id}")->assertJsonPath('data.coverage.objectives_planned', 0);
});

// ── The confirmed class, «Tus clases» and its closing ───────────────────────

function confirmedClass(array $s, string $date = '2026-11-02'): ClassSession
{
    $class = ClassSession::factory()->create([
        'school_id' => $s['school']->id,
        'group_id' => $s['group']->id,
        'subject_id' => $s['subject']->id,
        'unit_id' => $s['unit']->id,
        'date' => $date,
        'status' => ClassSessionStatus::Planned,
        'sections' => [['key' => 'opening', 'content' => 'Apertura', 'duration_minutes' => 10]],
    ]);
    $class->unitObjectives()->sync(collect($s['objectives'])->pluck('id'));

    return $class;
}

it('shows a confirmed class to its teacher and to school-wide roles, never to others', function () {
    $s = mathClassSetup();
    $class = confirmedClass($s);
    $url = "/api/v1/class-sessions/{$class->id}";

    Sanctum::actingAs($s['teacher']);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.sections.0.content', 'Apertura')
        ->assertJsonPath('data.can_close', true)
        ->assertJsonCount(2, 'data.objectives');

    Sanctum::actingAs(User::factory()->forSchool($s['school'])->director()->create());
    $this->getJson($url)->assertOk()->assertJsonPath('data.can_close', false);

    Sanctum::actingAs(User::factory()->forSchool($s['school'])->teacher()->create());
    $this->getJson($url)->assertForbidden();

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->director()->create());
    $this->getJson($url)->assertNotFound();
});

it('closes a class in one tap with «Todo»', function () {
    $this->travelTo('2026-11-19 12:00:00');
    $s = mathClassSetup();
    $class = confirmedClass($s);
    Sanctum::actingAs($s['teacher']);

    $this->postJson("/api/v1/class-sessions/{$class->id}/close", ['result' => 'full'])
        ->assertOk()->assertJsonPath('data.closing_result', 'full');

    expect($class->refresh()->status)->toBe(ClassSessionStatus::Delivered)
        ->and($class->unitObjectives()->pluck('class_session_unit_objective.coverage')->unique()->all())->toBe(['full']);
    $this->getJson("/api/v1/annual-plans/{$s['plan']->id}")->assertJsonPath('data.coverage.objectives_given', 2);
});

it('closes with «Una parte» marking the objectives given; the rest are recorded in part', function () {
    $this->travelTo('2026-11-19 12:00:00');
    $s = mathClassSetup();
    $class = confirmedClass($s);
    Sanctum::actingAs($s['teacher']);

    $this->postJson("/api/v1/class-sessions/{$class->id}/close", [
        'result' => 'partial', 'given_objective_ids' => [$s['objectives'][0]->id],
    ])->assertOk();

    $objectives = collect($this->getJson("/api/v1/annual-plans/{$s['plan']->id}")->json('data.units.0.objectives'));
    expect($objectives->firstWhere('id', $s['objectives'][0]->id)['status'])->toBe('given')
        ->and($objectives->firstWhere('id', $s['objectives'][1]->id)['status'])->toBe('started');
});

it('closes with «Nada», keeping the objectives as planned', function () {
    $this->travelTo('2026-11-19 12:00:00');
    $s = mathClassSetup();
    $class = confirmedClass($s);
    Sanctum::actingAs($s['teacher']);

    $this->postJson("/api/v1/class-sessions/{$class->id}/close", ['result' => 'none'])->assertOk();

    expect($class->refresh()->status)->toBe(ClassSessionStatus::Planned)
        ->and($class->closing_result?->value)->toBe('none');
    $this->getJson("/api/v1/annual-plans/{$s['plan']->id}")
        ->assertJsonPath('data.coverage.objectives_given', 0)
        ->assertJsonPath('data.coverage.objectives_planned', 2);
});

it('rejects closing before the class day, a cancelled class, or marking objectives the class does not work', function () {
    $this->travelTo('2026-11-19 12:00:00');
    $s = mathClassSetup();
    Sanctum::actingAs($s['teacher']);

    $future = confirmedClass($s, '2026-11-25');
    $this->postJson("/api/v1/class-sessions/{$future->id}/close", ['result' => 'full'])
        ->assertStatus(422)->assertJsonValidationErrors('result');

    $cancelled = confirmedClass($s);
    $cancelled->update(['status' => ClassSessionStatus::Cancelled]);
    $this->postJson("/api/v1/class-sessions/{$cancelled->id}/close", ['result' => 'full'])
        ->assertStatus(422)->assertJsonValidationErrors('result');

    $class = confirmedClass($s);
    $foreign = classObjective(Unit::factory()->create(['annual_plan_id' => $s['plan']->id]));
    $this->postJson("/api/v1/class-sessions/{$class->id}/close", ['result' => 'partial', 'given_objective_ids' => [$foreign->id]])
        ->assertStatus(422)->assertJsonValidationErrors('given_objective_ids');
    $this->postJson("/api/v1/class-sessions/{$class->id}/close", ['result' => 'full', 'given_objective_ids' => [$s['objectives'][0]->id]])
        ->assertStatus(422)->assertJsonValidationErrors('given_objective_ids');
});

it('forbids closing someone else\'s class', function () {
    $this->travelTo('2026-11-19 12:00:00');
    $s = mathClassSetup();
    $class = confirmedClass($s);
    $url = "/api/v1/class-sessions/{$class->id}/close";

    $colleague = User::factory()->forSchool($s['school'])->teacher()->create();
    leadGroup($s['group'], $colleague);
    Sanctum::actingAs($colleague);
    $this->postJson($url, ['result' => 'full'])->assertForbidden();

    Sanctum::actingAs(User::factory()->forSchool($s['school'])->psychopedagogue()->create());
    $this->postJson($url, ['result' => 'full'])->assertForbidden();

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->teacher()->create());
    $this->postJson($url, ['result' => 'full'])->assertNotFound();

    expect($class->refresh()->closing_result)->toBeNull();
});

it('lists «Tus clases»: the teacher\'s open drafts and classes, nothing for school-wide roles', function () {
    $s = mathClassSetup();
    $draft = completedClassProposal($s);
    $applied = completedClassProposal($s);
    $applied->update(['status' => AIProposalStatus::Applied]);
    $class = confirmedClass($s);
    $otherGroup = Group::factory()->create(['school_id' => $s['school']->id]);
    ClassSession::factory()->create(['school_id' => $s['school']->id, 'group_id' => $otherGroup->id]);

    Sanctum::actingAs($s['teacher']);
    $this->getJson('/api/v1/my-classes')->assertOk()
        ->assertJsonCount(1, 'data.drafts')
        ->assertJsonPath('data.drafts.0.id', $draft->id)
        ->assertJsonPath('data.drafts.0.title', 'Comparar fracciones')
        ->assertJsonCount(1, 'data.classes')
        ->assertJsonPath('data.classes.0.id', $class->id);

    Sanctum::actingAs(User::factory()->forSchool($s['school'])->director()->create());
    $this->getJson('/api/v1/my-classes')->assertOk()->assertJsonCount(0, 'data.drafts')->assertJsonCount(0, 'data.classes');
});

it('corrects a «Todo» close to «Nada»: the class is no longer delivered', function () {
    $this->travelTo('2026-11-19 12:00:00');
    $s = mathClassSetup();
    $class = confirmedClass($s);
    Sanctum::actingAs($s['teacher']);

    $this->postJson("/api/v1/class-sessions/{$class->id}/close", ['result' => 'full'])->assertOk();
    $this->postJson("/api/v1/class-sessions/{$class->id}/close", ['result' => 'none'])->assertOk();

    expect($class->refresh()->status)->toBe(ClassSessionStatus::Planned)
        ->and($class->closing_result?->value)->toBe('none');
    $this->getJson("/api/v1/annual-plans/{$s['plan']->id}")->assertJsonPath('data.coverage.objectives_given', 0);
});

it('never applies the same class draft twice', function () {
    $s = mathClassSetup();
    $proposal = completedClassProposal($s);
    Sanctum::actingAs($s['teacher']);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply", sectionDecisions())->assertOk();
    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply", sectionDecisions())->assertUnprocessable();

    // Even when the stale, already-loaded draft reaches the action (a request
    // that passed the controller's check before the other one committed).
    $stale = $proposal->replicate()->forceFill(['id' => $proposal->id, 'status' => AIProposalStatus::Completed]);
    $stale->exists = true;
    expect(fn () => app(ApplyProposal::class)($stale, $s['teacher'], sectionDecisions()))
        ->toThrow(HttpException::class);

    expect(ClassSession::query()->count())->toBe(1);
});
