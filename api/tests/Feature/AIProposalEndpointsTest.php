<?php

use App\Enums\AIProposalStatus;
use App\Enums\AIProposalType;
use App\Enums\ClassSessionStatus;
use App\Jobs\GenerateAIProposalJob;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\ClassSession;
use App\Models\CurricularFramework;
use App\Models\CurricularItem;
use App\Models\Group;
use App\Models\School;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * A school with a teacher who leads a group, plus that group's annual plan.
 *
 * @return array{0: School, 1: User, 2: Group, 3: AnnualPlan}
 */
function teacherWithGroupAndPlan(): array
{
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $plan = AnnualPlan::factory()->create([
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
        'school_id' => $school->id,
    ]);

    return [$school, $teacher, $group, $plan];
}

$unitDraft = [
    'name' => 'Unidad de Fracciones',
    'suggested_position' => 2,
    'suggested_start_date' => '2026-03-01',
    'suggested_end_date' => '2026-03-21',
    'suggested_curricular_items' => ['MAT-001'],
    'sessions' => [
        ['title' => 'Intro', 'objective' => 'Reconocer fracciones', 'duration_minutes' => 45],
        ['title' => 'Práctica', 'objective' => 'Operar con fracciones', 'duration_minutes' => 45],
    ],
];

it('queues a generation and returns 202 with a pending proposal', function () {
    Queue::fake();
    [$school, $teacher, $group, $plan] = teacherWithGroupAndPlan();
    Sanctum::actingAs($teacher);

    $response = $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", [
        'type' => 'unit',
        'parameters' => ['annual_plan_id' => $plan->id, 'focus' => 'fracciones'],
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.type', 'unit');

    Queue::assertPushed(GenerateAIProposalJob::class);
    $this->assertDatabaseHas('ai_proposals', [
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
        'status' => AIProposalStatus::Pending->value,
    ]);
});

it('forbids a teacher from generating for a group they do not lead', function () {
    Queue::fake();
    $school = School::factory()->create();
    $outsider = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($outsider);

    $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", [
        'type' => 'class_session',
        'parameters' => ['focus' => 'x'],
    ])->assertForbidden();

    Queue::assertNothingPushed();
});

it('rejects a referenced id that belongs to another group (no cross-tenant leak)', function () {
    Queue::fake();
    [$school, $teacher, $group] = teacherWithGroupAndPlan();
    $otherSchool = School::factory()->create();
    $otherGroup = Group::factory()->create(['school_id' => $otherSchool->id]);
    $otherPlan = AnnualPlan::factory()->create(['group_id' => $otherGroup->id, 'school_id' => $otherSchool->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", [
        'type' => 'unit',
        'parameters' => ['annual_plan_id' => $otherPlan->id, 'focus' => 'x'],
    ])->assertStatus(422);
});

it('validates required per-type parameters', function () {
    Queue::fake();
    [$school, $teacher, $group] = teacherWithGroupAndPlan();
    Sanctum::actingAs($teacher);

    // assessment requires focus + a valid assessment_type
    $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", [
        'type' => 'assessment',
        'parameters' => ['focus' => 'álgebra', 'assessment_type' => 'not_a_real_type'],
    ])->assertStatus(422)->assertJsonValidationErrors('parameters.assessment_type');
});

it('lets the requester and a school-wide role poll a proposal, but not an unrelated teacher', function () {
    [$school, $teacher, $group] = teacherWithGroupAndPlan();
    $director = User::factory()->forSchool($school)->director()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $proposal = AIProposal::factory()->create([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
    ]);

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/ai-proposals/{$proposal->id}")->assertOk()->assertJsonPath('data.id', $proposal->id);

    Sanctum::actingAs($director);
    $this->getJson("/api/v1/ai-proposals/{$proposal->id}")->assertOk();

    Sanctum::actingAs($otherTeacher);
    $this->getJson("/api/v1/ai-proposals/{$proposal->id}")->assertForbidden();
});

it('applies a completed unit proposal, creating the Unit and its ClassSessions', function () use ($unitDraft) {
    [$school, $teacher, $group, $plan] = teacherWithGroupAndPlan();
    CurricularItem::factory()->create(['code' => 'MAT-001']);
    $proposal = AIProposal::factory()->completed($unitDraft)->create([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
        'type' => AIProposalType::Unit,
        'input_parameters' => ['annual_plan_id' => $plan->id, 'focus' => 'fracciones'],
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply")
        ->assertOk()
        ->assertJsonPath('data.status', 'applied');

    $unit = Unit::firstOrFail();
    expect($unit->annual_plan_id)->toBe($plan->id)
        ->and($unit->name)->toBe('Unidad de Fracciones')
        ->and($unit->curricularItems)->toHaveCount(1);

    $sessions = ClassSession::where('unit_id', $unit->id)->get();
    expect($sessions)->toHaveCount(2)
        ->and($sessions->first()->group_id)->toBe($group->id)
        ->and($sessions->first()->status)->toBe(ClassSessionStatus::Planned);

    $proposal->refresh();
    expect($proposal->applied_to_id)->toBe($unit->id)
        ->and($proposal->applied_to_type)->toBe($unit->getMorphClass());

    $this->assertDatabaseHas('usage_events', [
        'event_type' => 'ai_proposal.applied',
        'user_id' => $teacher->id,
    ]);
});

it('applies a completed annual_plan proposal with the applier as the author', function () {
    [$school, $teacher, $group] = teacherWithGroupAndPlan();
    // Link a framework to the group so the plan's FK validation resolves.
    $frameworkModel = CurricularFramework::factory()->create();
    $group->curricularFrameworks()->attach($frameworkModel);

    $proposal = AIProposal::factory()->completed(['description' => 'Plan anual generado'])->create([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
        'type' => AIProposalType::AnnualPlan,
        'input_parameters' => [
            'curricular_framework_id' => $frameworkModel->id,
            'subject' => 'Matemática',
            'year' => now()->year,
            'language' => 'Español',
        ],
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply")->assertOk();

    $plan = AnnualPlan::where('description', 'Plan anual generado')->firstOrFail();
    expect($plan->teacher_id)->toBe($teacher->id)
        ->and($plan->group_id)->toBe($group->id)
        ->and($plan->subject)->toBe('Matemática');
});

it('applies a completed assessment proposal, syncing curricular items', function () {
    [$school, $teacher, $group] = teacherWithGroupAndPlan();
    CurricularItem::factory()->create(['code' => 'LEN-010']);
    $proposal = AIProposal::factory()->completed([
        'purpose' => 'Evaluar comprensión lectora',
        'duration_minutes' => 60,
        'content' => ['questions' => [['q' => '¿Idea principal?']]],
        'suggested_curricular_items' => ['LEN-010'],
    ])->create([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
        'type' => AIProposalType::Assessment,
        'input_parameters' => ['focus' => 'lectura', 'assessment_type' => 'written'],
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply")->assertOk();

    $assessment = Assessment::firstOrFail();
    expect($assessment->teacher_id)->toBe($teacher->id)
        ->and($assessment->type->value)->toBe('written')
        ->and($assessment->purpose)->toBe('Evaluar comprensión lectora')
        ->and($assessment->curricularItems)->toHaveCount(1);
});

it('forbids a teacher from applying another teacher proposal', function () {
    [$school, $teacher, $group, $plan] = teacherWithGroupAndPlan();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $proposal = AIProposal::factory()->completed(['name' => 'U', 'sessions' => []])->create([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
        'type' => AIProposalType::Unit,
        'input_parameters' => ['annual_plan_id' => $plan->id],
    ]);
    Sanctum::actingAs($otherTeacher);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply")->assertForbidden();
    expect($proposal->refresh()->status)->toBe(AIProposalStatus::Completed);
    expect(Unit::count())->toBe(0);
});

it('rejects applying a proposal that is not completed', function () {
    [$school, $teacher, $group, $plan] = teacherWithGroupAndPlan();
    $proposal = AIProposal::factory()->pending()->create([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
        'type' => AIProposalType::Unit,
        'input_parameters' => ['annual_plan_id' => $plan->id],
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply")->assertStatus(422);
});

it('lets the requester discard a proposal', function () {
    [$school, $teacher, $group] = teacherWithGroupAndPlan();
    $proposal = AIProposal::factory()->create([
        'group_id' => $group->id,
        'requested_by_id' => $teacher->id,
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/discard")
        ->assertOk()
        ->assertJsonPath('data.status', 'discarded');

    expect($proposal->refresh()->status)->toBe(AIProposalStatus::Discarded);
});

it('runs the full generate -> poll -> apply flow end to end with Http::fake', function () use ($unitDraft) {
    Http::fake(['api.anthropic.com/*' => Http::response(
        ['content' => [['type' => 'text', 'text' => json_encode($unitDraft)]], 'stop_reason' => 'end_turn'],
        200,
    )]);
    [$school, $teacher, $group, $plan] = teacherWithGroupAndPlan();
    Sanctum::actingAs($teacher);

    // generate (sync queue runs the job during the request)
    $generate = $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", [
        'type' => 'unit',
        'parameters' => ['annual_plan_id' => $plan->id, 'focus' => 'fracciones'],
    ])->assertStatus(202);
    $id = $generate->json('data.id');

    // poll
    $this->getJson("/api/v1/ai-proposals/{$id}")->assertOk()->assertJsonPath('data.status', 'completed');

    // apply
    $this->postJson("/api/v1/ai-proposals/{$id}/apply")->assertOk()->assertJsonPath('data.status', 'applied');

    expect(Unit::where('name', 'Unidad de Fracciones')->exists())->toBeTrue()
        ->and(ClassSession::count())->toBe(2);
});

it('throttles generation to the configured hourly limit per school (429 on the 21st)', function () {
    Queue::fake();
    [$school, $teacher, $group, $plan] = teacherWithGroupAndPlan();
    Sanctum::actingAs($teacher);
    $body = ['type' => 'unit', 'parameters' => ['annual_plan_id' => $plan->id, 'focus' => 'x']];

    for ($i = 0; $i < 20; $i++) {
        $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", $body)->assertStatus(202);
    }

    $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", $body)->assertStatus(429);
});
