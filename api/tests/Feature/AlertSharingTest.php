<?php

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3jpzdp follow-up (Eitán): an alert can only be handed to or
// shared with someone who already sees the student; a teacher only hands it
// to / shares it with psychopedagogy or direction, and only they hand it to /
// share it with a teacher; an alert can be in the charge of two people at
// once, who both see it, leave notes and can close it.

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    $this->math = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Matemática']);
    $this->language = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Idioma Español']);

    $this->mariana = User::factory()->forSchool($this->school)->teacher()->create(['name' => 'Mariana López']);
    leadGroup($this->group, $this->mariana, $this->math);
    // Ana also teaches the student (another subject): she sees the student,
    // but a teacher still can't hand her an alert.
    $this->ana = User::factory()->forSchool($this->school)->teacher()->create(['name' => 'Ana Fernández']);
    leadGroup($this->group, $this->ana, $this->language);
    // Pedro teaches in the school but not this student.
    $this->pedro = User::factory()->forSchool($this->school)->teacher()->create(['name' => 'Pedro Suárez']);
    leadGroup(Group::factory()->create(['school_id' => $this->school->id]), $this->pedro);

    $this->galia = User::factory()->forSchool($this->school)->psychopedagogue()->create(['name' => 'Galia Cohen']);
    $this->adriana = User::factory()->forSchool($this->school)->director()->create(['name' => 'Adriana Ruiz']);

    $this->student = Student::factory()->create(['school_id' => $this->school->id, 'full_name' => 'Lucía Pereyra']);
    $this->student->groups()->attach($this->group, ['school_year' => now()->year]);

    $this->alert = Tenancy::forSchool($this->school, fn () => Alert::factory()->create([
        'student_id' => $this->student->id,
        'subject_id' => $this->math->id,
        'type' => AlertType::Performance,
        'recipients' => ['teacher'],
    ]));

    $this->handOff = function (User $from, User $to, int $days = 7) {
        Sanctum::actingAs($from);

        return $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
            'outcome' => 'handed_off',
            'assignee_id' => $to->id,
            'due_on' => now()->addDays($days)->toDateString(),
        ]);
    };
    $this->share = function (User $from, User $with, ?string $note = null) {
        Sanctum::actingAs($from);

        return $this->postJson("/api/v1/alerts/{$this->alert->id}/share", ['user_id' => $with->id, 'note' => $note]);
    };
    $this->candidateIds = function (User $actor): array {
        Sanctum::actingAs($actor);

        return collect($this->getJson("/api/v1/alerts/{$this->alert->id}/handoff-candidates")->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    };
});

it('offers a teacher only psychopedagogy and direction among the people who see the student', function () {
    expect(($this->candidateIds)($this->mariana))
        ->toBe(collect([$this->galia->id, $this->adriana->id])->sort()->values()->all());
});

it('offers psychopedagogy the teachers of that student, never one who does not see them', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    expect(($this->candidateIds)($this->galia))
        ->toBe(collect([$this->mariana->id, $this->ana->id, $this->adriana->id])->sort()->values()->all());
});

it('rejects a teacher handing the alert to another teacher, even one who sees the student', function () {
    ($this->handOff)($this->mariana, $this->ana)->assertJsonValidationErrors('assignee_id');
    ($this->handOff)($this->mariana, $this->pedro)->assertJsonValidationErrors('assignee_id');

    expect($this->alert->fresh()->outcome)->toBeNull();
});

it('lets a teacher hand the alert to psychopedagogy or direction', function () {
    ($this->handOff)($this->mariana, $this->adriana)
        ->assertOk()
        ->assertJsonPath('data.assignee.name', 'Adriana Ruiz');
});

it('rejects handing the alert to someone who does not see the student', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    ($this->handOff)($this->galia, $this->pedro)->assertJsonValidationErrors('assignee_id');
    ($this->share)($this->galia, $this->pedro)->assertJsonValidationErrors('user_id');
});

it('rejects a teacher sharing the alert with another teacher', function () {
    Sanctum::actingAs($this->mariana);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'owned', 'due_on' => now()->addWeek()->toDateString(),
    ])->assertOk()->assertJsonPath('data.can.share', true);

    ($this->share)($this->mariana, $this->ana)->assertJsonValidationErrors('user_id');
    expect($this->alert->collaborators()->count())->toBe(0);

    ($this->share)($this->mariana, $this->galia)->assertOk()
        ->assertJsonPath('data.collaborators.0.name', 'Galia Cohen');
});

it('lets psychopedagogy share with the teacher, and then both follow it together', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    ($this->share)($this->galia, $this->mariana, 'La sigo con vos.')
        ->assertOk()
        ->assertJsonPath('data.assignee.name', 'Galia Cohen')
        ->assertJsonPath('data.collaborators.0.name', 'Mariana López')
        ->assertJsonPath('data.actions.1.kind', 'share')
        ->assertJsonPath('data.actions.1.assignee.name', 'Mariana López');

    // Sharing keeps the outcome, the person responsible and the deadline.
    $alert = $this->alert->fresh();
    expect($alert->outcome->value)->toBe('handed_off')
        ->and($alert->assignee_id)->toBe($this->galia->id)
        ->and($alert->due_on->toDateString())->toBe(now()->addDays(7)->toDateString());

    // Both see it and may do everything with it.
    foreach ([$this->galia, $this->mariana] as $person) {
        Sanctum::actingAs($person);
        $this->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can.act', true)
            ->assertJsonPath('data.0.can.note', true)
            ->assertJsonPath('data.0.can.resolve', true);

        $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => "Nota de {$person->name}"])
            ->assertOk();
    }

    Sanctum::actingAs($this->galia);
    $this->getJson('/api/v1/alerts')
        ->assertJsonPath('data.0.actions.2.kind', 'note')
        ->assertJsonPath('data.0.actions.2.note', 'Nota de Galia Cohen')
        ->assertJsonPath('data.0.actions.3.actor.name', 'Mariana López');

    // Mariana takes it: she is now responsible and Galia stays with her.
    Sanctum::actingAs($this->mariana);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'owned', 'due_on' => now()->addWeeks(2)->toDateString(),
    ])->assertOk()
        ->assertJsonPath('data.assignee.name', 'Mariana López')
        ->assertJsonPath('data.collaborators.0.name', 'Galia Cohen');

    // Either one closes it.
    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertOk();
    expect($this->alert->fresh()->resolved)->toBeTrue();
});

it('lets the co-responsible close it too', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    ($this->share)($this->galia, $this->mariana)->assertOk();

    Sanctum::actingAs($this->mariana);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertOk();
});

it('is in the charge of two people at most', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    ($this->share)($this->galia, $this->mariana)->assertOk();

    ($this->share)($this->galia, $this->adriana)->assertForbidden();
    ($this->share)($this->mariana, $this->adriana)->assertForbidden();

    Sanctum::actingAs($this->galia);
    $this->getJson('/api/v1/alerts')->assertJsonPath('data.0.can.share', false);
});

it('cannot be shared before a way out was chosen, nor by someone not in charge', function () {
    ($this->share)($this->mariana, $this->galia)->assertForbidden();

    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    // Mariana handed it off: she keeps seeing it but is no longer in charge.
    ($this->share)($this->mariana, $this->adriana)->assertForbidden();
    Sanctum::actingAs($this->mariana);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'x'])->assertForbidden();
    $this->getJson('/api/v1/alerts')
        ->assertJsonPath('data.0.can.note', false)
        ->assertJsonPath('data.0.can.share', false);

    // Someone who doesn't see the alert at all.
    Sanctum::actingAs($this->ana);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'x'])->assertForbidden();
});

it('validates the share and the note', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    ($this->share)($this->galia, $this->galia)->assertJsonValidationErrors('user_id');
    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/share", [])->assertJsonValidationErrors('user_id');
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => ''])->assertJsonValidationErrors('note');
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => str_repeat('a', 2001)])
        ->assertJsonValidationErrors('note');
});

it('hands the alert off as a transfer: the co-responsible keeps seeing it but is no longer in charge', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    ($this->share)($this->galia, $this->mariana)->assertOk();

    ($this->handOff)($this->galia, $this->adriana)->assertOk()->assertJsonCount(0, 'data.collaborators');

    Sanctum::actingAs($this->mariana);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.can.act', false)
        ->assertJsonPath('data.0.assignee.name', 'Adriana Ruiz');
});

it('escalates an overdue hand-off to the co-responsible too, and sharing does not reset it', function () {
    ($this->handOff)($this->mariana, $this->galia, 2)->assertOk();
    ($this->share)($this->galia, $this->ana)->assertOk();

    $this->travel(4)->days();
    $this->artisan('alerts:escalate')->assertSuccessful();

    $escalation = Alert::query()->withoutGlobalScopes()->where('type', AlertType::EscalatedOverdue->value)->sole();
    expect($escalation->people()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$this->mariana->id, $this->galia->id, $this->ana->id])->sort()->values()->all());

    // A note is not a way out: the escalation stays open.
    Sanctum::actingAs($this->ana);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'Hablé con la familia.'])->assertOk();
    expect($escalation->fresh()->resolved)->toBeFalse();

    // Choosing a way out — by either of the two — closes it.
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'observing', 'due_on' => now()->addWeek()->toDateString(),
    ])->assertOk();
    expect($escalation->fresh()->resolved)->toBeTrue();
});

it('keeps sharing inside the school', function () {
    $otherSchool = School::factory()->create();
    $outsider = User::factory()->forSchool($otherSchool)->psychopedagogue()->create();
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    expect(($this->candidateIds)($this->galia))->not->toContain($outsider->id);
    ($this->share)($this->galia, $outsider)->assertJsonValidationErrors('user_id');
    ($this->handOff)($this->galia, $outsider)->assertJsonValidationErrors('assignee_id');

    Sanctum::actingAs($outsider);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/share", ['user_id' => $this->adriana->id])->assertNotFound();
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'x'])->assertNotFound();
});

it('shows who has the alert on the group card only to whoever sees that alert', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    ($this->share)($this->galia, $this->mariana)->assertOk();

    foreach ([$this->mariana, $this->galia] as $viewer) {
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/groups/{$this->group->id}/alerts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.assignee.name', 'Galia Cohen')
            ->assertJsonPath('data.0.collaborators.0.name', 'Mariana López')
            ->assertJsonPath('data.0.outcome_at', fn ($value) => $value !== null);
    }

    // Ana teaches the group (another subject) and direction wasn't reached:
    // neither sees the alert, so neither learns who has it.
    foreach ([$this->ana, $this->adriana] as $viewer) {
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/groups/{$this->group->id}/alerts")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['name' => 'Galia Cohen']);
    }
});
