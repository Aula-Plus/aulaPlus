<?php

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3jpzdp, product review of 14/10: when the deadline of any of the
// three ways out arrives and the alert is still pending, the SAME alert is
// marked «Llegó a su fecha sin salida» — no second "escalated" alert. Holder,
// deadline, movements and notes stay in the thread. Whoever has it is told;
// whoever handed it off is not; Direction is never added by a deadline.

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    $this->math = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Matemática']);
    $this->teacher = User::factory()->forSchool($this->school)->teacher()->create(['name' => 'Mariana López']);
    leadGroup($this->group, $this->teacher, $this->math);
    $this->psychopedagogue = User::factory()->forSchool($this->school)->psychopedagogue()->create(['name' => 'Galia Cohen']);
    $this->director = User::factory()->forSchool($this->school)->director()->create(['name' => 'Adriana Ruiz']);

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
            'note' => 'Primera nota.',
        ]));
    };
    $this->overdueNotices = fn (User $user) => $user->notifications()->get()
        ->filter(fn ($notice) => ($notice->data['type'] ?? null) === 'alert_overdue');
});

dataset('outcomes', [
    'me ocupo yo' => ['owned', 'teacher'],
    'se la paso a otro rol' => ['handed_off', 'psychopedagogue'],
    'la dejo en observación' => ['observing', 'teacher'],
]);

it('marks the same alert when the deadline passes, for any of the three ways out', function (string $outcome, string $holderKey) {
    $holder = $this->{$holderKey};
    ($this->choose)($this->teacher, $outcome, 3, $outcome === 'handed_off' ? $holder : null)->assertOk();
    $dueOn = now()->addDays(3)->toDateString();
    $alertsBefore = Alert::query()->withoutGlobalScopes()->count();

    $this->travel(5)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();
    $this->artisan('alerts:overdue')->assertSuccessful();

    // No second alert, escalated or otherwise.
    expect(Alert::query()->withoutGlobalScopes()->count())->toBe($alertsBefore)
        ->and(Alert::query()->withoutGlobalScopes()->where('type', AlertType::EscalatedOverdue->value)->count())->toBe(0);

    // The same case, marked, with holder and deadline untouched.
    $alert = $this->alert->fresh();
    expect($alert->overdue_at)->not->toBeNull()
        ->and($alert->resolved)->toBeFalse()
        ->and($alert->outcome->value)->toBe($outcome)
        ->and($alert->assignee_id)->toBe($holder->id)
        ->and($alert->due_on->toDateString())->toBe($dueOn);

    // The thread keeps the movement and its note, plus one overdue step.
    Sanctum::actingAs($holder);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->alert->id)
        ->assertJsonPath('data.0.is_overdue', true)
        ->assertJsonPath('data.0.overdue_at', fn ($value) => $value !== null)
        ->assertJsonPath('data.0.can.act', true)
        ->assertJsonCount(2, 'data.0.actions')
        ->assertJsonPath('data.0.actions.0.kind', 'outcome')
        ->assertJsonPath('data.0.actions.0.note', 'Primera nota.')
        ->assertJsonPath('data.0.actions.1.kind', 'overdue')
        ->assertJsonPath('data.0.actions.1.actor', null)
        ->assertJsonPath('data.0.actions.1.assignee.id', $holder->id)
        ->assertJsonPath('data.0.actions.1.due_on', $dueOn);

    // Whoever has it is told, once.
    $notices = ($this->overdueNotices)($holder);
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->data)->toBe([
            'type' => 'alert_overdue',
            'alert_id' => $this->alert->id,
            'student_id' => $this->student->id,
            'due_date' => $dueOn,
        ]);

    // Direction is never added by a deadline.
    expect(($this->overdueNotices)($this->director))->toHaveCount(0)
        ->and($alert->people()->pluck('users.id')->all())->not->toContain($this->director->id);
    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
})->with('outcomes');

it('does not notify whoever handed it off: they only read it', function () {
    ($this->choose)($this->teacher, 'handed_off', 2, $this->psychopedagogue)->assertOk();

    $this->travel(4)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();

    expect(($this->overdueNotices)($this->psychopedagogue))->toHaveCount(1)
        ->and(($this->overdueNotices)($this->teacher))->toHaveCount(0);

    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonPath('data.0.is_overdue', true)
        ->assertJsonPath('data.0.can.act', false);
});

it('does not notify a holder who no longer sees the student, but still marks the case', function () {
    // Psychopedagogy hands it to the teacher, who then stops teaching the group.
    ($this->choose)($this->teacher, 'handed_off', 7, $this->psychopedagogue)->assertOk();
    ($this->choose)($this->psychopedagogue, 'handed_off', 2, $this->teacher)->assertOk();
    DB::table('group_teacher')->where('teacher_id', $this->teacher->id)->delete();

    $this->travel(4)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();

    expect($this->alert->fresh()->overdue_at)->not->toBeNull()
        ->and(($this->overdueNotices)($this->teacher))->toHaveCount(0)
        ->and(($this->overdueNotices)($this->psychopedagogue))->toHaveCount(0);
});

it('keeps Direction configured from the start as a reader, without adding or notifying it', function () {
    Tenancy::forSchool($this->school, fn () => $this->alert->update(['recipients' => ['teacher', 'director']]));
    ($this->choose)($this->teacher, 'owned', 2)->assertOk();

    $this->travel(4)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();

    // Direction sees it because the school's settings put it there from the
    // start, not because of the deadline: it is not added to the people the
    // alert reaches individually, nor notified, nor given the alert.
    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_overdue', true)
        ->assertJsonPath('data.0.can.act', false);
    expect(($this->overdueNotices)($this->director))->toHaveCount(0)
        ->and($this->alert->people()->pluck('users.id')->all())->not->toContain($this->director->id);
});

it('does not mark before the deadline, without a way out, or once closed', function () {
    $this->travel(10)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();
    expect($this->alert->fresh()->overdue_at)->toBeNull();

    ($this->choose)($this->teacher, 'owned', 3)->assertOk();
    $this->travel(3)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();
    expect($this->alert->fresh()->overdue_at)->toBeNull();

    Sanctum::actingAs($this->teacher);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertOk();
    $this->travel(3)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();
    expect($this->alert->fresh()->overdue_at)->toBeNull()
        ->and(($this->overdueNotices)($this->teacher))->toHaveCount(0);
});

it('clears the mark when a new way out sets a new deadline, and marks it again if that one passes', function () {
    ($this->choose)($this->teacher, 'observing', 2)->assertOk();
    $this->travel(3)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();
    expect($this->alert->fresh()->overdue_at)->not->toBeNull();

    ($this->choose)($this->teacher, 'owned', 5)->assertOk()->assertJsonPath('data.overdue_at', null);
    expect($this->alert->fresh()->overdue_at)->toBeNull();

    $this->travel(6)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();
    expect($this->alert->fresh()->overdue_at)->not->toBeNull()
        ->and($this->alert->actions()->where('kind', 'overdue')->count())->toBe(2)
        ->and(($this->overdueNotices)($this->teacher))->toHaveCount(2);
});

it('logs the overdue mark with ids only', function () {
    ($this->choose)($this->teacher, 'owned', 1)->assertOk();
    $this->travel(3)->days();

    Log::shouldReceive('info')->once()->with('status-change.alert.overdue', Mockery::on(
        fn (array $context) => $context['alert_id'] === $this->alert->id
            && ! array_key_exists('description', $context)
            && ! array_key_exists('note', $context)
    ));

    $this->artisan('alerts:overdue')->assertSuccessful();
});

it('marks each school\'s overdue alerts inside that school only', function () {
    $otherSchool = School::factory()->create();
    $otherTeacher = User::factory()->forSchool($otherSchool)->teacher()->create();
    $otherStudent = Student::factory()->create(['school_id' => $otherSchool->id]);

    $overdue = fn (School $school, Student $student, User $holder) => Tenancy::forSchool($school, fn () => Alert::factory()->create([
        'student_id' => $student->id,
        'type' => AlertType::Performance,
        'recipients' => ['teacher'],
        'outcome' => 'owned',
        'outcome_by_id' => $holder->id,
        'outcome_at' => now()->subDays(10),
        'assignee_id' => $holder->id,
        'due_on' => now()->subDays(2)->toDateString(),
    ]));
    $mine = $overdue($this->school, $this->student, $this->teacher);
    $theirs = $overdue($otherSchool, $otherStudent, $otherTeacher);

    $this->artisan('alerts:overdue')->assertSuccessful();

    foreach ([[$mine, $this->school], [$theirs, $otherSchool]] as [$alert, $school]) {
        expect($alert->fresh()->overdue_at)->not->toBeNull();
        $step = DB::table('alert_actions')->where('alert_id', $alert->id)->where('kind', 'overdue')->sole();
        expect($step->school_id)->toBe($school->id);
    }
});

it('still closes a legacy escalated alert when the alert it escalates moves, and creates none', function () {
    ($this->choose)($this->teacher, 'handed_off', 1, $this->psychopedagogue)->assertOk();
    $escalation = Tenancy::forSchool($this->school, function () {
        $escalation = Alert::factory()->create([
            'student_id' => $this->student->id,
            'source_alert_id' => $this->alert->id,
            'type' => AlertType::EscalatedOverdue,
            'recipients' => [],
        ]);
        $escalation->people()->sync([$this->teacher->id, $this->psychopedagogue->id]);

        return $escalation;
    });

    $this->travel(3)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();
    expect(Alert::query()->withoutGlobalScopes()->where('type', AlertType::EscalatedOverdue->value)->count())->toBe(1)
        ->and($escalation->fresh()->overdue_at)->toBeNull();

    ($this->choose)($this->psychopedagogue, 'owned', 7)->assertOk();
    expect($escalation->fresh()->resolved)->toBeTrue();
});

it('no longer lets a school route the escalated alert', function () {
    Sanctum::actingAs($this->director);

    $this->putJson('/api/v1/alert-routing/escalated_overdue', ['recipients' => ['director']])
        ->assertJsonValidationErrors('type');
    $this->getJson('/api/v1/alert-settings')
        ->assertOk()
        ->assertJsonMissing(['type' => 'escalated_overdue']);
    $this->putJson('/api/v1/alert-routing/performance', ['recipients' => []])->assertUnprocessable();
    $this->putJson('/api/v1/alert-routing/performance', ['recipients' => ['teacher', 'director']])->assertOk();
});
