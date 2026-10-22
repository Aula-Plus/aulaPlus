<?php

use App\Enums\AIProposalStatus;
use App\Enums\AIProposalType;
use App\Jobs\GenerateAIProposalJob;
use App\Models\AIProposal;
use App\Models\Group;
use App\Models\School;
use App\Models\Subject;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3qkxtc: creation journeys on Dirección's adoption panel.

/**
 * @return array{0: School, 1: User, 2: Group, 3: Subject, 4: User}
 */
function journeySchool(): array
{
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $subject = leadGroup($group, $teacher);
    $director = User::factory()->forSchool($school)->director()->create();

    return [$school, $teacher, $group, $subject, $director];
}

function journeysUrl(School $school, User $teacher): string
{
    return "/api/v1/schools/{$school->id}/adoption-dashboard/teachers/{$teacher->id}/journeys";
}

it('records a browser-reported step once per journey', function () {
    [, $teacher, $group] = journeySchool();
    Sanctum::actingAs($teacher);
    $journeyId = (string) Str::uuid();

    $payload = ['journey_id' => $journeyId, 'step' => 'form_opened', 'content_type' => 'assessment', 'group_id' => $group->id];
    $this->postJson('/api/v1/usage/journey-steps', $payload)->assertNoContent();
    $this->postJson('/api/v1/usage/journey-steps', $payload)->assertNoContent();
    $this->postJson('/api/v1/usage/journey-steps', [...$payload, 'step' => 'filling_started'])->assertNoContent();

    expect(UsageEvent::query()->where('journey_id', $journeyId)->pluck('step')->all())
        ->toBe(['form_opened', 'filling_started']);
    $this->assertDatabaseHas('usage_events', [
        'user_id' => $teacher->id,
        'journey_id' => $journeyId,
        'step' => 'form_opened',
        'event_type' => 'journey.form_opened',
        'content_type' => 'assessment',
        'group_id' => $group->id,
        'metadata' => null,
    ]);
});

it('only lets teachers report journey steps', function () {
    [$school] = journeySchool();
    $payload = ['journey_id' => (string) Str::uuid(), 'step' => 'form_opened', 'content_type' => 'assessment'];

    $this->postJson('/api/v1/usage/journey-steps', $payload)->assertUnauthorized();

    foreach ([User::factory()->forSchool($school)->director()->create(), User::factory()->forSchool($school)->psychopedagogue()->create()] as $actor) {
        Sanctum::actingAs($actor);
        $this->postJson('/api/v1/usage/journey-steps', $payload)->assertForbidden();
    }

    expect(UsageEvent::query()->whereNotNull('journey_id')->count())->toBe(0);
});

it('rejects unknown or server-only steps, unknown content types and foreign groups', function (array $override, string $field) {
    [$school, $teacher] = journeySchool();
    $foreignGroup = Group::factory()->create(['school_id' => School::factory()->create()->id]);
    $notMyGroup = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $payload = ['journey_id' => (string) Str::uuid(), 'step' => 'form_opened', 'content_type' => 'assessment', ...$override];
    $payload = array_map(fn ($value) => match ($value) {
        'FOREIGN_GROUP' => $foreignGroup->id,
        'NOT_MY_GROUP' => $notMyGroup->id,
        default => $value,
    }, $payload);

    $this->postJson('/api/v1/usage/journey-steps', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown step' => [['step' => 'stopped_at_field'], 'step'],
    'server-only step' => [['step' => 'creation_confirmed'], 'step'],
    'unknown content type' => [['content_type' => 'project'], 'content_type'],
    'no form yet for programs' => [['content_type' => 'annual_plan'], 'content_type'],
    'not a uuid' => [['journey_id' => 'abc'], 'journey_id'],
    'group of another school' => [['group_id' => 'FOREIGN_GROUP'], 'group_id'],
    'group the teacher does not lead' => [['group_id' => 'NOT_MY_GROUP'], 'group_id'],
]);

it('links the confirmed creation of an assessment to its journey, server-side', function () {
    [, $teacher, $group, $subject] = journeySchool();
    Sanctum::actingAs($teacher);
    $journeyId = (string) Str::uuid();

    $id = $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written',
        'administered_at' => '2026-10-10',
        'subject_id' => $subject->id,
        'journey_id' => $journeyId,
    ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('usage_events', [
        'user_id' => $teacher->id,
        'event_type' => 'assessment.created',
        'journey_id' => $journeyId,
        'step' => 'creation_confirmed',
        'content_type' => 'assessment',
        'content_id' => $id,
        'group_id' => $group->id,
    ]);
});

it('rejects a malformed journey id on assessment creation', function () {
    [, $teacher, $group, $subject] = journeySchool();
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written', 'administered_at' => '2026-10-10', 'subject_id' => $subject->id, 'journey_id' => 'nope',
    ])->assertUnprocessable()->assertJsonValidationErrors('journey_id');
});

it('lists a teacher journeys with each step date and time, for the director only', function () {
    [$school, $teacher, $group, $subject, $director] = journeySchool();
    Sanctum::actingAs($teacher);
    $journeyId = (string) Str::uuid();

    $this->travelTo(now()->startOfMonth()->addDays(2)->setTime(9, 15));
    $this->postJson('/api/v1/usage/journey-steps', ['journey_id' => $journeyId, 'step' => 'form_opened', 'content_type' => 'assessment', 'group_id' => $group->id]);
    $this->travel(2)->minutes();
    $this->postJson('/api/v1/usage/journey-steps', ['journey_id' => $journeyId, 'step' => 'filling_started', 'content_type' => 'assessment', 'group_id' => $group->id]);
    $this->travel(5)->minutes();
    $assessmentId = $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written', 'administered_at' => '2026-10-10', 'subject_id' => $subject->id, 'journey_id' => $journeyId,
    ])->json('data.id');

    // Another teacher's step reusing the same journey id never merges in.
    $intruder = User::factory()->forSchool($school)->teacher()->create();
    UsageEvent::factory()->create([
        'school_id' => $school->id, 'user_id' => $intruder->id, 'event_type' => 'journey.form_opened',
        'step' => 'form_opened', 'journey_id' => $journeyId, 'content_type' => 'assessment', 'created_at' => now(),
    ]);

    Sanctum::actingAs($director);
    $response = $this->getJson(journeysUrl($school, $teacher))->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    $journey = $response->json('data.0');
    expect($journey['id'])->toBe($journeyId)
        ->and($journey['flow'])->toBe('manual')
        ->and($journey['content_type'])->toBe('assessment')
        ->and($journey['confirmed'])->toBeTrue()
        ->and(collect($journey['steps'])->pluck('step')->all())->toBe(['form_opened', 'filling_started', 'creation_confirmed'])
        ->and($journey['steps'][0]['at'])->toContain('T09:15:00')
        ->and($journey['last_step']['step'])->toBe('creation_confirmed')
        ->and($journey['group_name'])->toBe($group->name)
        ->and($journey['subject_name'])->toBe($subject->name)
        ->and($journey['content'])->toBe(['type' => 'assessment', 'id' => $assessmentId, 'group_id' => $group->id]);
    expect($response->json('meta.data_since.form_opened'))->not->toBeNull()
        ->and($response->json('meta.data_since.generation_requested'))->toBeNull();

    $otherDirector = User::factory()->forSchool(School::factory()->create())->director()->create();
    foreach ([$teacher, User::factory()->forSchool($school)->psychopedagogue()->create(), $otherDirector] as $actor) {
        Sanctum::actingAs($actor);
        $this->getJson(journeysUrl($school, $teacher))->assertForbidden();
    }
});

it('returns 404 for a teacher of another school and 422 for a malformed month', function () {
    [$school, , , , $director] = journeySchool();
    $foreignTeacher = User::factory()->forSchool(School::factory()->create())->teacher()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($director);

    $this->getJson(journeysUrl($school, $foreignTeacher))->assertNotFound();
    $this->getJson(journeysUrl($school, $teacher).'?month=octubre')->assertUnprocessable();
    $this->getJson(journeysUrl($school, $teacher).'?month='.now()->format('Y-m'))->assertOk()->assertJsonPath('data', []);
});

it('shows a confirmed creation recorded without a journey as its own journey', function () {
    [$school, $teacher, , , $director] = journeySchool();
    UsageEvent::factory()->create([
        'school_id' => $school->id, 'user_id' => $teacher->id, 'event_type' => 'class_session.created',
        'step' => 'creation_confirmed', 'content_type' => 'class_session', 'metadata' => ['id' => 999], 'created_at' => now(),
    ]);
    Sanctum::actingAs($director);

    $journey = $this->getJson(journeysUrl($school, $teacher))->assertOk()->json('data.0');

    expect($journey['flow'])->toBeNull()
        ->and($journey['confirmed'])->toBeTrue()
        ->and($journey['content'])->toBeNull()
        ->and(collect($journey['steps'])->pluck('step')->all())->toBe(['creation_confirmed']);
});

it('counts one AI attempt per journey: retries and change requests stay inside it', function () {
    Queue::fake();
    [$school, $teacher, $group, $subject, $director] = journeySchool();

    // Before any generation is recorded, attempts are «dato no disponible», not 0.
    Sanctum::actingAs($director);
    expect($this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard/teachers")->json('data.0.month_attempts'))
        ->toBe(['annual_plans' => null, 'class_sessions' => null, 'assessments' => null]);

    Sanctum::actingAs($teacher);
    $journeyId = (string) Str::uuid();
    $body = ['type' => 'assessment', 'journey_id' => $journeyId, 'parameters' => [
        'focus' => 'fracciones', 'assessment_type' => 'written', 'subject_id' => $subject->id,
    ]];
    $first = $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", $body)->assertAccepted()->json('data.id');
    $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", $body)->assertAccepted();

    // A change request referencing the first draft inherits its journey.
    $withoutJourney = $body;
    unset($withoutJourney['journey_id']);
    $withoutJourney['parameters']['reference_proposal_id'] = $first;
    $third = $this->postJson("/api/v1/groups/{$group->id}/assistant/generate", $withoutJourney)->assertAccepted()->json('data.id');

    expect(AIProposal::find($third)->journey_id)->toBe($journeyId);
    expect(UsageEvent::query()->where('journey_id', $journeyId)->pluck('step')->all())
        ->toBe(['generation_requested', 'regeneration_requested', 'regeneration_requested']);

    Sanctum::actingAs($director);
    expect($this->getJson("/api/v1/schools/{$school->id}/adoption-dashboard/teachers")->json('data.0.month_attempts'))
        ->toBe(['annual_plans' => 0, 'class_sessions' => 0, 'assessments' => 1]);

    $journey = $this->getJson(journeysUrl($school, $teacher))->json('data.0');
    expect($journey['flow'])->toBe('ai')
        ->and($journey['subject_name'])->toBe($subject->name)
        ->and($journey['confirmed'])->toBeFalse();
});

it('records the generation outcome with a failure category, never the technical error', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);
    [$school, $teacher, $group, , $director] = journeySchool();
    $journeyId = (string) Str::uuid();
    $proposal = AIProposal::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'requested_by_id' => $teacher->id,
        'journey_id' => $journeyId, 'type' => AIProposalType::ClassSession,
        'input_parameters' => ['focus' => 'x'], 'status' => AIProposalStatus::Pending,
    ]);

    GenerateAIProposalJob::dispatchSync($proposal);

    $event = UsageEvent::query()->withoutGlobalScopes()->where('journey_id', $journeyId)->sole();
    expect($event->step)->toBe('generation_failed')
        ->and($event->user_id)->toBe($teacher->id)
        ->and($event->school_id)->toBe($school->id)
        ->and($event->metadata)->toBe(['ai_proposal_id' => $proposal->id, 'failure_reason' => 'provider_error']);

    Sanctum::actingAs($director);
    $response = $this->getJson(journeysUrl($school, $teacher))->assertOk();
    expect($response->json('data.0.last_step'))->toMatchArray(['step' => 'generation_failed', 'failure_reason' => 'provider_error']);
    expect($response->getContent())->not->toContain('HTTP 500')->not->toContain('boom');
});

it('records a discarded draft and a confirmed AI creation inside the proposal journey', function () {
    [$school, $teacher, $group, $subject] = journeySchool();
    $journeyId = (string) Str::uuid();
    $make = fn () => AIProposal::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'requested_by_id' => $teacher->id,
        'journey_id' => $journeyId, 'type' => AIProposalType::Assessment,
        'input_parameters' => ['focus' => 'x', 'assessment_type' => 'written', 'subject_id' => $subject->id],
        'raw_response' => ['purpose' => 'Repaso'], 'status' => AIProposalStatus::Completed,
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/ai-proposals/'.$make()->id.'/discard')->assertOk();
    $this->postJson('/api/v1/ai-proposals/'.$make()->id.'/apply')->assertOk();

    expect(UsageEvent::query()->where('journey_id', $journeyId)->pluck('step')->all())
        ->toBe(['draft_discarded', 'creation_confirmed']);
});

it('lists the requested month even when today is past that month\'s last day', function () {
    [$school, $teacher, , , $director] = journeySchool();
    $this->travelTo('2026-10-31 12:00:00');

    $confirmed = fn (string $at) => UsageEvent::factory()->create([
        'school_id' => $school->id, 'user_id' => $teacher->id, 'event_type' => 'assessment.created',
        'step' => 'creation_confirmed', 'content_type' => 'assessment', 'created_at' => $at,
    ]);
    $february = $confirmed('2026-02-20 10:00:00');
    $confirmed('2026-03-02 10:00:00');
    Sanctum::actingAs($director);

    $response = $this->getJson(journeysUrl($school, $teacher).'?month=2026-02')->assertOk();

    expect($response->json('meta.month'))->toBe('2026-02')
        ->and($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe('event-'.$february->id);
});
