<?php

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3jpzdp, product review of 14/10: «Crear seguimiento vinculado».
// From an alert, whoever may act on it creates a follow-up linked to it, to
// organise the work with several people. They are separate records: creating
// or resolving the follow-up never closes the alert nor changes its
// deadline, and vice versa. No two-person limit on the follow-up.

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    $this->math = Subject::factory()->create(['school_id' => $this->school->id]);
    $this->language = Subject::factory()->create(['school_id' => $this->school->id]);
    $this->mariana = User::factory()->forSchool($this->school)->teacher()->create(['name' => 'Mariana López']);
    leadGroup($this->group, $this->mariana, $this->math);
    $this->ana = User::factory()->forSchool($this->school)->teacher()->create(['name' => 'Ana Fernández']);
    leadGroup($this->group, $this->ana, $this->language);
    $this->galia = User::factory()->forSchool($this->school)->psychopedagogue()->create(['name' => 'Galia Cohen']);
    $this->adriana = User::factory()->forSchool($this->school)->director()->create(['name' => 'Adriana Ruiz']);
    $this->otherPsychopedagogue = User::factory()->forSchool($this->school)->psychopedagogue()->create();

    $this->student = Student::factory()->create(['school_id' => $this->school->id]);
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
    $this->link = function (User $actor, array $extra = []) {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/students/{$this->student->id}/scheduled-follow-ups", [
            'description' => 'Armar el plan con la familia y el equipo.',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'alert_id' => $this->alert->id,
            ...$extra,
        ]);
    };
});

it('lets whoever has the alert create a linked follow-up with several people, without touching the alert', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    $before = $this->alert->fresh();

    ($this->link)($this->galia, [
        'assigned_to_id' => $this->adriana->id,
        // More than two people: the follow-up has no two-person limit.
        'shared_with_ids' => [$this->mariana->id, $this->ana->id, $this->otherPsychopedagogue->id],
    ])
        ->assertCreated()
        ->assertJsonPath('data.alert_id', $this->alert->id)
        ->assertJsonPath('data.assigned_to_id', $this->adriana->id)
        ->assertJsonCount(3, 'data.shared_with');

    // The alert keeps its state, holder, deadline and people.
    $after = $this->alert->fresh();
    expect($after->resolved)->toBeFalse()
        ->and($after->outcome)->toBe($before->outcome)
        ->and($after->assignee_id)->toBe($this->galia->id)
        ->and($after->due_on->toDateString())->toBe($before->due_on->toDateString())
        ->and($after->people()->pluck('users.id')->sort()->values()->all())
        ->toBe($before->people()->pluck('users.id')->sort()->values()->all());

    // The link shows both ways.
    $followUp = ScheduledFollowUp::query()->withoutGlobalScopes()->sole();
    Sanctum::actingAs($this->galia);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonPath('data.0.follow_ups.0.id', $followUp->id)
        ->assertJsonPath('data.0.follow_ups.0.resolved', false)
        ->assertJsonPath('data.0.can.link_follow_up', true);
    $this->getJson("/api/v1/students/{$this->student->id}/scheduled-follow-ups")
        ->assertOk()
        ->assertJsonPath('data.0.alert_id', $this->alert->id);
});

it('lets someone the alert reaches link a follow-up before any way out was chosen', function () {
    Sanctum::actingAs($this->mariana);
    $this->getJson('/api/v1/alerts')->assertJsonPath('data.0.can.link_follow_up', true);

    ($this->link)($this->mariana)->assertCreated();
    expect($this->alert->fresh()->outcome)->toBeNull();
});

it('forbids linking for whoever handed the alert off, or someone it does not reach', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    // Mariana handed it off: she reads it, but does not act on it.
    ($this->link)($this->mariana)->assertForbidden();
    // Ana and direction see the student, but the alert does not reach them.
    ($this->link)($this->ana)->assertForbidden();
    ($this->link)($this->adriana)->assertForbidden();

    expect(ScheduledFollowUp::query()->withoutGlobalScopes()->count())->toBe(0);

    // Without the link, any of them can still schedule a follow-up as usual.
    ($this->link)($this->adriana, ['alert_id' => null])->assertCreated()->assertJsonPath('data.alert_id', null);
});

it('forbids linking a closed alert', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertOk();

    ($this->link)($this->galia)->assertForbidden();
});

it('only links an alert about the same student, of the same school', function () {
    $otherStudent = Student::factory()->create(['school_id' => $this->school->id]);
    $otherStudent->groups()->attach($this->group, ['school_year' => now()->year]);
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/students/{$otherStudent->id}/scheduled-follow-ups", [
        'description' => 'x',
        'due_date' => now()->addWeek()->toDateString(),
        'alert_id' => $this->alert->id,
    ])->assertJsonValidationErrors('alert_id');

    $otherSchool = School::factory()->create();
    $foreignAlert = Tenancy::forSchool($otherSchool, fn () => Alert::factory()->create([
        'student_id' => Student::factory()->create(['school_id' => $otherSchool->id])->id,
        'type' => AlertType::Performance,
        'recipients' => ['psychopedagogue'],
    ]));
    ($this->link)($this->galia, ['alert_id' => $foreignAlert->id])->assertJsonValidationErrors('alert_id');
    ($this->link)($this->galia, ['alert_id' => 'abc'])->assertJsonValidationErrors('alert_id');

    expect(ScheduledFollowUp::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps each record\'s state, deadline and closing independent', function () {
    ($this->handOff)($this->mariana, $this->galia, 2)->assertOk();
    ($this->link)($this->galia)->assertCreated();
    $followUp = ScheduledFollowUp::query()->withoutGlobalScopes()->sole();

    // Resolving the follow-up does not close the alert.
    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/scheduled-follow-ups/{$followUp->id}/resolve")->assertOk();
    $alert = $this->alert->fresh();
    expect($alert->resolved)->toBeFalse()
        ->and($alert->due_on->toDateString())->toBe(now()->addDays(2)->toDateString());

    // The alert's deadline passing marks only the alert.
    $this->travel(4)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();
    expect($this->alert->fresh()->overdue_at)->not->toBeNull()
        ->and($followUp->fresh()->resolved_at->isPast())->toBeTrue();

    // A second linked follow-up, then the alert is resolved: the follow-up stays open.
    ($this->link)($this->galia)->assertCreated();
    $open = ScheduledFollowUp::query()->withoutGlobalScopes()->where('resolved', false)->sole();
    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertOk();
    expect($open->fresh()->resolved)->toBeFalse()
        ->and($open->fresh()->alert_id)->toBe($this->alert->id);
});
