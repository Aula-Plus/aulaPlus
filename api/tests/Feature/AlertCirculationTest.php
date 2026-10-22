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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3jpzdp follow-up (Eitán) and product review of 14/10: an alert
// can only be handed to someone who already sees the student; a teacher only
// hands it to psychopedagogy or direction, and only they hand it to a
// teacher. Handing it off is a transfer to exactly one person: whoever had it
// keeps reading it — no actions, no notes — while they still see the student.
// Sharing the alert itself no longer exists (working a case together is done
// on a linked follow-up, see LinkedFollowUpTest).

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
    $this->candidateIds = function (User $actor): array {
        Sanctum::actingAs($actor);

        return collect($this->getJson("/api/v1/alerts/{$this->alert->id}/handoff-candidates")->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    };
});

it('has no endpoint to share an alert any more', function () {
    expect(collect(Route::getRoutes()->getRoutes())->map->uri()->filter(fn ($uri) => str_ends_with($uri, '/share'))->values()->all())
        ->toBe([]);

    ($this->handOff)($this->mariana, $this->galia)->assertOk()
        ->assertJsonMissingPath('data.collaborators')
        ->assertJsonMissingPath('data.can.share');

    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/share", ['user_id' => $this->adriana->id])->assertNotFound();
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
});

it('rejects a list of people: handing it off names exactly one', function () {
    Sanctum::actingAs($this->mariana);

    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'handed_off',
        'assignee_id' => [$this->galia->id, $this->adriana->id],
        'due_on' => now()->addWeek()->toDateString(),
    ])->assertJsonValidationErrors('assignee_id');
});

it('transfers the alert to exactly one person: whoever handed it off keeps reading it, without actions', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    ($this->handOff)($this->galia, $this->adriana)->assertOk()->assertJsonPath('data.assignee.name', 'Adriana Ruiz');

    $alert = $this->alert->fresh();
    expect($alert->assignee_id)->toBe($this->adriana->id);

    // Both who handed it off along the way keep seeing it, read only.
    foreach ([$this->mariana, $this->galia] as $previous) {
        Sanctum::actingAs($previous);
        $this->getJson('/api/v1/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.assignee.name', 'Adriana Ruiz')
            ->assertJsonPath('data.0.is_in_charge', false)
            ->assertJsonPath('data.0.can.act', false)
            ->assertJsonPath('data.0.can.note', false)
            ->assertJsonPath('data.0.can.resolve', false)
            ->assertJsonPath('data.0.can.link_follow_up', false);

        $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
            'outcome' => 'owned', 'due_on' => now()->addWeek()->toDateString(),
        ])->assertForbidden();
        $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertForbidden();
        $this->getJson("/api/v1/alerts/{$this->alert->id}/handoff-candidates")->assertForbidden();
    }

    // Only the one who has it now acts on it.
    Sanctum::actingAs($this->adriana);
    $this->getJson('/api/v1/alerts')
        ->assertJsonPath('data.0.is_in_charge', true)
        ->assertJsonPath('data.0.can.act', true)
        ->assertJsonPath('data.0.can.note', true)
        ->assertJsonPath('data.0.can.resolve', true);
});

it('stops showing the alert to whoever handed it off once they no longer see the student', function () {
    // Galia hands it to Mariana; Mariana then hands it back to direction.
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    ($this->handOff)($this->galia, $this->mariana)->assertOk();
    ($this->handOff)($this->mariana, $this->adriana)->assertOk();

    // The recipients snapshot reaches teachers of math — drop it, so only
    // the individual reach (alert_user) is left for Mariana.
    Alert::query()->withoutGlobalScopes()->whereKey($this->alert->id)->update(['recipients' => json_encode([])]);

    Sanctum::actingAs($this->mariana);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data');

    // Mariana stops teaching the group: she no longer sees the student, so
    // she no longer reads the alert either.
    DB::table('group_teacher')->where('teacher_id', $this->mariana->id)->delete();

    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/groups/{$this->group->id}/alerts")->assertForbidden();
});

it('takes notes only from whoever has it, never before a way out was chosen', function () {
    // Nobody chose yet: the alert reaches Mariana, but nobody has it.
    Sanctum::actingAs($this->mariana);
    $this->getJson('/api/v1/alerts')
        ->assertJsonPath('data.0.is_in_charge', false)
        ->assertJsonPath('data.0.can.act', true)
        ->assertJsonPath('data.0.can.note', false);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'x'])->assertForbidden();

    // Once she takes it, she has it and may.
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'owned', 'due_on' => now()->addWeek()->toDateString(),
    ])->assertOk()->assertJsonPath('data.can.note', true);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'Hablé con la familia.'])
        ->assertOk()
        ->assertJsonPath('data.actions.1.kind', 'note')
        ->assertJsonPath('data.actions.1.note', 'Hablé con la familia.')
        ->assertJsonPath('data.actions.1.actor.name', 'Mariana López');

    // A note is not a way out: holder and deadline stay.
    expect($this->alert->fresh()->assignee_id)->toBe($this->mariana->id);

    // Someone who doesn't see the alert at all.
    Sanctum::actingAs($this->ana);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'x'])->assertForbidden();
});

it('validates the note', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => ''])->assertJsonValidationErrors('note');
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => str_repeat('a', 2001)])
        ->assertJsonValidationErrors('note');
});

it('takes no notes on a resolved alert', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();
    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertOk();

    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'x'])->assertForbidden();
    expect($this->alert->actions()->count())->toBe(1);
});

it('takes no notes on a legacy escalated alert', function () {
    ($this->handOff)($this->mariana, $this->galia, 2)->assertOk();
    $escalation = Tenancy::forSchool($this->school, function () {
        $escalation = Alert::factory()->create([
            'student_id' => $this->student->id,
            'source_alert_id' => $this->alert->id,
            'type' => AlertType::EscalatedOverdue,
            'recipients' => [],
        ]);
        $escalation->people()->sync([$this->mariana->id, $this->galia->id]);

        return $escalation;
    });

    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/alerts/{$escalation->id}/notes", ['note' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/alerts/{$escalation->id}/outcome", [
        'outcome' => 'owned', 'due_on' => now()->addWeek()->toDateString(),
    ])->assertForbidden();
});

it('keeps handing off inside the school', function () {
    $otherSchool = School::factory()->create();
    $outsider = User::factory()->forSchool($otherSchool)->psychopedagogue()->create();
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    expect(($this->candidateIds)($this->galia))->not->toContain($outsider->id);
    ($this->handOff)($this->galia, $outsider)->assertJsonValidationErrors('assignee_id');

    Sanctum::actingAs($outsider);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'x'])->assertNotFound();
});

it('shows who has the alert on the group card only to whoever sees that alert', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    foreach ([$this->mariana, $this->galia] as $viewer) {
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/groups/{$this->group->id}/alerts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.assignee.name', 'Galia Cohen')
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

it('fails cleanly when the alert is about a student who was removed', function () {
    Sanctum::actingAs($this->mariana);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/outcome", [
        'outcome' => 'owned', 'due_on' => now()->addWeek()->toDateString(),
    ])->assertOk();

    $this->student->delete();

    // Nobody can receive it any more: no candidates, and a hand-off is
    // rejected as invalid instead of crashing.
    expect(($this->candidateIds)($this->mariana))->toBe([]);
    ($this->handOff)($this->mariana, $this->galia)->assertJsonValidationErrors('assignee_id');

    // Whoever has it can still close it.
    Sanctum::actingAs($this->mariana);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertOk();
});

it('lists the candidates in a constant number of queries, however many staff the school has', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    $queriesFor = function (): int {
        Sanctum::actingAs($this->galia);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/v1/alerts/{$this->alert->id}/handoff-candidates")->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $queriesFor(); // warm-up: the permission cache loads once per process
    $before = $queriesFor();
    foreach (range(1, 5) as $i) {
        leadGroup($this->group, User::factory()->forSchool($this->school)->teacher()->create(), $this->language);
        User::factory()->forSchool($this->school)->teacher()->create();
    }

    expect($queriesFor())->toBe($before)
        ->and(($this->candidateIds)($this->galia))->toHaveCount(3 + 5);
});

it('re-checks under the lock that the actor still has it when choosing a way out', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    // Galia's hand-off passes the policy, but before it is written she hands
    // the alert to Adriana from another tab: she no longer has it.
    Gate::after(function (User $user, string $ability): void {
        if ($ability === 'act' && $user->is($this->galia)) {
            Alert::query()->withoutGlobalScopes()->whereKey($this->alert->id)->update(['assignee_id' => $this->adriana->id]);
        }
    });

    ($this->handOff)($this->galia, $this->mariana)->assertForbidden();

    expect($this->alert->fresh()->assignee_id)->toBe($this->adriana->id)
        ->and($this->alert->actions()->count())->toBe(1);
});

it('re-checks under the lock that the actor still has it when resolving', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    // Galia's "resolve" passes the policy, but before it is written the
    // alert is handed to Adriana from another tab: Galia no longer has it.
    Gate::after(function (User $user, string $ability): void {
        if ($ability === 'resolve' && $user->is($this->galia)) {
            Alert::query()->withoutGlobalScopes()->whereKey($this->alert->id)->update(['assignee_id' => $this->adriana->id]);
        }
    });

    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/resolve")->assertForbidden();

    expect($this->alert->fresh()->resolved)->toBeFalse();
});

it('re-checks under the lock that the actor still has it when leaving a note', function () {
    ($this->handOff)($this->mariana, $this->galia)->assertOk();

    // Galia's note passes the policy, but before it is written the alert is
    // handed to Adriana from another tab: Galia only reads it now.
    Gate::after(function (User $user, string $ability): void {
        if ($ability === 'note' && $user->is($this->galia)) {
            Alert::query()->withoutGlobalScopes()->whereKey($this->alert->id)->update(['assignee_id' => $this->adriana->id]);
        }
    });

    Sanctum::actingAs($this->galia);
    $this->postJson("/api/v1/alerts/{$this->alert->id}/notes", ['note' => 'Tarde.'])->assertForbidden();

    expect($this->alert->actions()->where('kind', 'note')->count())->toBe(0);
});
