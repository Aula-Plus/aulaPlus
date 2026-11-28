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

// ClickUp 86e3jpzdp, PO decision of 24/10: whoever handed an alert off may
// switch on «Avisarme a mí también cuando llegue el plazo». Off by default,
// changed from reading the alert, for any role, kept if the alert is handed
// off again; with it on, they are told when the deadline passes.

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    $this->math = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Matemática']);
    $this->teacher = User::factory()->forSchool($this->school)->teacher()->create();
    leadGroup($this->group, $this->teacher, $this->math);
    $this->psychopedagogue = User::factory()->forSchool($this->school)->psychopedagogue()->create();
    $this->director = User::factory()->forSchool($this->school)->director()->create();

    $this->student = Student::factory()->create(['school_id' => $this->school->id]);
    $this->student->groups()->attach($this->group, ['school_year' => now()->year]);

    $this->alert = Tenancy::forSchool($this->school, fn () => Alert::factory()->create([
        'student_id' => $this->student->id,
        'subject_id' => $this->math->id,
        'type' => AlertType::Performance,
        'recipients' => ['teacher'],
    ]));

    $this->choose = function (User $actor, string $outcome, int $days, ?User $to = null) {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", array_filter([
            'outcome' => $outcome,
            'assignee_id' => $to?->id,
            'due_on' => now()->addDays($days)->toDateString(),
        ]));
    };
    $this->toggle = function (User $actor, mixed $enabled) {
        Sanctum::actingAs($actor);

        return $this->putJson("/api/v1/alerts/{$this->alert->id}/deadline-notice", ['enabled' => $enabled]);
    };
    $this->overdueNotices = fn (User $user) => $user->notifications()->get()
        ->filter(fn ($notice) => ($notice->data['type'] ?? null) === 'alert_overdue');
});

it('offers the box, off, only to whoever handed the alert off', function () {
    ($this->choose)($this->teacher, 'handed_off', 3, $this->psychopedagogue)->assertOk();

    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonPath('data.0.deadline_notice', ['enabled' => false]);

    // Whoever has it is told anyway: no box for them.
    Sanctum::actingAs($this->psychopedagogue);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonPath('data.0.deadline_notice', null);
});

it('switches on and off from reading the alert, and tells whoever turned it on when the deadline passes', function () {
    ($this->choose)($this->teacher, 'handed_off', 2, $this->psychopedagogue)->assertOk();

    ($this->toggle)($this->teacher, true)->assertOk()->assertJsonPath('data.deadline_notice.enabled', true);
    ($this->toggle)($this->teacher, false)->assertOk()->assertJsonPath('data.deadline_notice.enabled', false);
    ($this->toggle)($this->teacher, true)->assertOk()->assertJsonPath('data.deadline_notice.enabled', true);

    $this->travel(4)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();
    $this->artisan('alerts:overdue')->assertSuccessful();

    $notices = ($this->overdueNotices)($this->teacher);
    expect($notices)->toHaveCount(1)
        // Ids and the deadline only (CLAUDE.md rule 11).
        ->and(array_keys($notices->first()->data))->toBe(['type', 'alert_id', 'student_id', 'due_date'])
        ->and(($this->overdueNotices)($this->psychopedagogue))->toHaveCount(1)
        ->and(($this->overdueNotices)($this->director))->toHaveCount(0);
});

it('does not tell whoever handed it off with the box off', function () {
    ($this->choose)($this->teacher, 'handed_off', 2, $this->psychopedagogue)->assertOk();

    $this->travel(4)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();

    expect(($this->overdueNotices)($this->teacher))->toHaveCount(0);
});

it('keeps the box when the alert is handed off again, for any role', function () {
    ($this->choose)($this->teacher, 'handed_off', 2, $this->psychopedagogue)->assertOk();
    ($this->toggle)($this->teacher, true)->assertOk();

    // Psicopedagogía hands it on to Dirección and asks to be told too.
    ($this->choose)($this->psychopedagogue, 'handed_off', 2, $this->director)->assertOk();
    ($this->toggle)($this->psychopedagogue, true)->assertOk()->assertJsonPath('data.deadline_notice.enabled', true);

    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonPath('data.0.deadline_notice.enabled', true);

    $this->travel(4)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();

    expect(($this->overdueNotices)($this->teacher))->toHaveCount(1)
        ->and(($this->overdueNotices)($this->psychopedagogue))->toHaveCount(1)
        ->and(($this->overdueNotices)($this->director))->toHaveCount(1);
});

it('tells only once whoever handed it off and got it back', function () {
    ($this->choose)($this->teacher, 'handed_off', 2, $this->psychopedagogue)->assertOk();
    ($this->toggle)($this->teacher, true)->assertOk();
    ($this->choose)($this->psychopedagogue, 'handed_off', 2, $this->teacher)->assertOk();

    // The teacher has it again: no box while they hold it.
    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonPath('data.0.deadline_notice', null);

    $this->travel(4)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();

    expect(($this->overdueNotices)($this->teacher))->toHaveCount(1);
});

it('does not tell a disabled user who turned it on', function () {
    ($this->choose)($this->teacher, 'handed_off', 2, $this->psychopedagogue)->assertOk();
    ($this->toggle)($this->teacher, true)->assertOk();
    $this->teacher->forceFill(['disabled_at' => now()])->save();

    $this->travel(4)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();

    expect(($this->overdueNotices)($this->teacher))->toHaveCount(0);
});

it('forbids the box to whoever has it, never handed it off, or another school', function () {
    // Nobody handed it off yet.
    ($this->toggle)($this->teacher, true)->assertForbidden();

    ($this->choose)($this->teacher, 'handed_off', 2, $this->psychopedagogue)->assertOk();

    // Whoever has it now.
    ($this->toggle)($this->psychopedagogue, true)->assertForbidden();
    // Someone who never handed it off.
    ($this->toggle)($this->director, true)->assertForbidden();

    $outsider = User::factory()->forSchool(School::factory()->create())->director()->create();
    Sanctum::actingAs($outsider);
    expect($this->putJson("/api/v1/alerts/{$this->alert->id}/deadline-notice", ['enabled' => true])->status())
        ->toBeIn([403, 404]);

    expect($this->alert->people()->wherePivot('notify_at_deadline', true)->count())->toBe(0);
});

it('forbids the box on a closed alert', function () {
    ($this->choose)($this->teacher, 'owned', 2)->assertOk();
    ($this->choose)($this->teacher, 'handed_off', 2, $this->psychopedagogue)->assertOk();
    $this->alert->forceFill(['resolved' => true, 'resolved_at' => now()])->save();

    ($this->toggle)($this->teacher, true)->assertForbidden();
});

it('validates the box value', function () {
    ($this->choose)($this->teacher, 'handed_off', 2, $this->psychopedagogue)->assertOk();

    ($this->toggle)($this->teacher, 'sí')->assertStatus(422)->assertJsonValidationErrors('enabled');
    Sanctum::actingAs($this->teacher);
    $this->putJson("/api/v1/alerts/{$this->alert->id}/deadline-notice", [])
        ->assertStatus(422)->assertJsonValidationErrors('enabled');
});

it('requires authentication', function () {
    $this->putJson("/api/v1/alerts/{$this->alert->id}/deadline-notice", ['enabled' => true])->assertUnauthorized();
});
