<?php

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3jpzdp — "Las tres salidas de una alerta": me ocupo yo / se la
// paso a otro rol / la dejo en observación, each with responsible and
// deadline, and the "alerta escalada con plazo vencido".

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    $this->math = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Matemática']);
    $this->teacher = User::factory()->forSchool($this->school)->teacher()->create(['name' => 'Mariana López']);
    leadGroup($this->group, $this->teacher, $this->math);
    $this->psychopedagogue = User::factory()->forSchool($this->school)->psychopedagogue()->create(['name' => 'Galia Cohen']);
    $this->director = User::factory()->forSchool($this->school)->director()->create();

    $this->student = Student::factory()->create(['school_id' => $this->school->id]);
    $this->student->groups()->attach($this->group, ['school_year' => now()->year]);

    $this->alert = Tenancy::forSchool($this->school, fn () => Alert::factory()->create([
        'student_id' => $this->student->id,
        'subject_id' => $this->math->id,
        'type' => AlertType::Performance,
        'recipients' => ['teacher'],
    ]));
});

it('lets the teacher take the alert ("me ocupo yo") with a deadline', function () {
    Sanctum::actingAs($this->teacher);

    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonPath('data.0.can.act', true)
        ->assertJsonPath('data.0.can.resolve', false);

    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'owned',
        'due_on' => now()->addWeek()->toDateString(),
    ])->assertOk()
        ->assertJsonPath('data.outcome', 'owned')
        ->assertJsonPath('data.assignee.name', 'Mariana López')
        ->assertJsonPath('data.due_on', now()->addWeek()->toDateString())
        ->assertJsonPath('data.can.resolve', true)
        ->assertJsonCount(1, 'data.actions');

    expect($this->alert->fresh()->resolved)->toBeFalse();

    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertOk();
});

it('does not let anyone close a new alert without choosing a way out first', function () {
    Sanctum::actingAs($this->teacher);

    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertForbidden();
});

it('hands the alert to psychopedagogy, who sees it from then on as responsible', function () {
    Sanctum::actingAs($this->psychopedagogue);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');

    Sanctum::actingAs($this->teacher);
    $this->getJson("/api/v1/alerts/{$this->alert->id}/handoff-candidates")
        ->assertOk()
        ->assertJsonFragment(['id' => $this->psychopedagogue->id, 'name' => 'Galia Cohen'])
        ->assertJsonMissing(['id' => $this->teacher->id, 'name' => 'Mariana López']);

    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'handed_off',
        'assignee_id' => $this->psychopedagogue->id,
        'due_on' => now()->addWeek()->toDateString(),
        'note' => 'Bajó de 7 a 4 en dos pruebas seguidas.',
    ])->assertOk()->assertJsonPath('data.assignee.id', $this->psychopedagogue->id);

    // The teacher keeps seeing it but is no longer the one to move it.
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonPath('data.0.can.act', false)
        ->assertJsonPath('data.0.actions.0.note', 'Bajó de 7 a 4 en dos pruebas seguidas.');
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'owned', 'due_on' => now()->addWeek()->toDateString(),
    ])->assertForbidden();

    Sanctum::actingAs($this->psychopedagogue);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.can.act', true)
        ->assertJsonPath('data.0.outcome_by.name', 'Mariana López');

    // Direction still doesn't see it.
    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
});

it('leaves the alert in observation with the actor as responsible', function () {
    Sanctum::actingAs($this->teacher);

    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'observing',
        'due_on' => now()->addWeeks(2)->toDateString(),
    ])->assertOk()
        ->assertJsonPath('data.outcome', 'observing')
        ->assertJsonPath('data.assignee.id', $this->teacher->id);
});

it('validates deadline, assignee and outcome', function () {
    $other = User::factory()->forSchool(School::factory()->create())->psychopedagogue()->create();
    $disabled = User::factory()->forSchool($this->school)->psychopedagogue()->create(['disabled_at' => now()]);
    Sanctum::actingAs($this->teacher);
    $url = "/api/v1/alerts/{$this->alert->id}/outcome";

    $this->postJson($url, ['outcome' => 'done', 'due_on' => now()->addDay()->toDateString()])
        ->assertJsonValidationErrors('outcome');
    $this->postJson($url, ['outcome' => 'owned'])->assertJsonValidationErrors('due_on');
    $this->postJson($url, ['outcome' => 'owned', 'due_on' => now()->subDay()->toDateString()])
        ->assertJsonValidationErrors('due_on');
    $this->postJson($url, ['outcome' => 'handed_off', 'due_on' => now()->addDay()->toDateString()])
        ->assertJsonValidationErrors('assignee_id');
    foreach ([$other->id, $disabled->id, $this->teacher->id] as $assigneeId) {
        $this->postJson($url, [
            'outcome' => 'handed_off', 'assignee_id' => $assigneeId, 'due_on' => now()->addDay()->toDateString(),
        ])->assertJsonValidationErrors('assignee_id');
    }
    $this->postJson($url, [
        'outcome' => 'owned', 'assignee_id' => $this->psychopedagogue->id, 'due_on' => now()->addDay()->toDateString(),
    ])->assertJsonValidationErrors('assignee_id');
});

it('forbids acting on an alert the user does not see', function () {
    Sanctum::actingAs($this->director);

    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'owned', 'due_on' => now()->addDay()->toDateString(),
    ])->assertForbidden();
    $this->getJson("/api/v1/alerts/{$this->alert->id}/handoff-candidates")->assertForbidden();
});

it('escalates a handed-off alert whose deadline passed to both people involved', function () {
    Sanctum::actingAs($this->teacher);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'handed_off',
        'assignee_id' => $this->psychopedagogue->id,
        'due_on' => now()->addDays(3)->toDateString(),
    ])->assertOk();

    $this->travel(5)->days();
    Log::shouldReceive('info')->once()->with('status-change.alert.escalated', Mockery::on(
        fn (array $context) => $context['source_alert_id'] === $this->alert->id && ! array_key_exists('description', $context)
    ));

    $this->artisan('alerts:escalate')->assertSuccessful();
    $this->artisan('alerts:escalate')->assertSuccessful();

    $escalations = Alert::query()->withoutGlobalScopes()->where('type', AlertType::EscalatedOverdue->value)->get();
    expect($escalations)->toHaveCount(1)
        ->and($escalations->first()->source_alert_id)->toBe($this->alert->id)
        ->and($escalations->first()->description)->toContain('Mariana López le pasó el caso a Galia Cohen');

    foreach ([$this->teacher, $this->psychopedagogue] as $person) {
        Sanctum::actingAs($person);
        $this->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonFragment(['type' => 'escalated_overdue', 'source_alert_id' => $this->alert->id]);
    }

    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');

    // The receiver finally moves it: the escalated alert closes.
    Sanctum::actingAs($this->psychopedagogue);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'owned', 'due_on' => now()->addWeek()->toDateString(),
    ])->assertOk();

    expect($escalations->first()->fresh()->resolved)->toBeTrue()
        ->and($this->alert->fresh()->escalated_at)->toBeNull();
});

it('does not escalate before the deadline nor non-handoff outcomes', function () {
    Sanctum::actingAs($this->teacher);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'owned', 'due_on' => now()->addDays(3)->toDateString(),
    ])->assertOk();

    $this->travel(10)->days();
    $this->artisan('alerts:escalate')->assertSuccessful();

    expect(Alert::query()->withoutGlobalScopes()->where('type', AlertType::EscalatedOverdue->value)->count())->toBe(0);
});

it('lets the school add direction to the escalated alert', function () {
    Sanctum::actingAs($this->director);
    $this->putJson('/api/v1/alert-routing/escalated_overdue', ['recipients' => ['director']])->assertOk();
    $this->putJson('/api/v1/alert-routing/escalated_overdue', ['recipients' => []])->assertOk();
    $this->putJson('/api/v1/alert-routing/performance', ['recipients' => []])->assertUnprocessable();
    $this->putJson('/api/v1/alert-routing/escalated_overdue', ['recipients' => ['director']])->assertOk();

    Sanctum::actingAs($this->teacher);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'handed_off',
        'assignee_id' => $this->psychopedagogue->id,
        'due_on' => now()->addDay()->toDateString(),
    ])->assertOk();

    $this->travel(3)->days();
    $this->artisan('alerts:escalate')->assertSuccessful();

    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'escalated_overdue')
        ->assertJsonPath('data.0.can.act', false);
});
