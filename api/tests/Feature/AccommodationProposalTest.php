<?php

use App\Enums\AccommodationReviewStatus;
use App\Models\Accommodation;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\AccommodationProposalReturnedNotification;
use App\Notifications\FollowUpAssignedNotification;
use App\Services\Accommodations\AccommodationProposalWorkflow;
use App\Support\SchoolTime;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/**
 * ClickUp 86e3qkwkk: direction proposes an accommodation, psychopedagogy
 * reviews it (edit + confirm, return for corrections, reject), direction may
 * correct and resubmit or withdraw it. Until confirmed it never reaches a
 * teacher and is never in force; the whole back-and-forth stays on the same
 * proposal.
 */
function proposalScenario(): array
{
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create(['name' => 'Directora']);
    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Ana Psico']);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    return compact('school', 'director', 'psycho', 'teacher', 'group', 'student');
}

function proposalPayload(array $overrides = []): array
{
    return [
        'category' => 'access',
        'type' => 'Más tiempo en escritos',
        'description' => 'Media hora extra en escritos.',
        'focus_area' => 'Escritura',
        ...$overrides,
    ];
}

/**
 * What direction adds when proposing: the review deadline and who reviews it.
 */
function reviewRouting(array $overrides = []): array
{
    return [
        'review_due_date' => now()->addDays(10)->toDateString(),
        'review_recipients' => 'role',
        ...$overrides,
    ];
}

function proposeAs(User $director, Student $student, array $overrides = []): Accommodation
{
    Sanctum::actingAs($director);
    $id = test()->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload([...reviewRouting(), ...$overrides]))
        ->assertCreated()
        ->json('data.id');

    return Accommodation::query()->findOrFail($id);
}

it('turns what direction creates into a pending proposal with a review task for psychopedagogy', function () {
    Notification::fake();
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    Sanctum::actingAs($director);

    $dueDate = now()->addDays(3)->toDateString();
    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload([
        'active' => false,
        ...reviewRouting(['review_due_date' => $dueDate]),
    ]))
        ->assertCreated()
        ->assertJsonPath('data.review_status', 'pending')
        ->assertJsonPath('data.proposed_by_id', $director->id)
        ->assertJsonPath('data.is_effective', false)
        ->assertJsonPath('data.active', true);

    $accommodation = Accommodation::query()->sole();
    $followUp = ScheduledFollowUp::query()->sole();
    expect($followUp->accommodation_id)->toBe($accommodation->id)
        // Nobody is made responsible on psychopedagogy's behalf: the whole
        // role gets it, with the deadline direction picked.
        ->and($followUp->assigned_to_id)->toBeNull()
        ->and($followUp->sharedWith()->pluck('users.id')->all())->toBe([$psycho->id])
        ->and($followUp->due_date->toDateString())->toBe($dueDate)
        ->and($followUp->resolved)->toBeFalse()
        // No proposal content is copied into the task.
        ->and($followUp->description)->toBe('');

    Notification::assertSentTo($psycho, FollowUpAssignedNotification::class);
    $this->assertDatabaseHas('accommodation_review_events', [
        'accommodation_id' => $accommodation->id,
        'user_id' => $director->id,
        'action' => 'submitted',
    ]);
});

it('lets psychopedagogy create an accommodation directly, with no review', function () {
    ['psycho' => $psycho, 'student' => $student] = proposalScenario();
    Sanctum::actingAs($psycho);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(['active' => true]))
        ->assertCreated()
        ->assertJsonPath('data.review_status', null)
        ->assertJsonPath('data.is_effective', true);

    expect(ScheduledFollowUp::query()->count())->toBe(0);
});

it('never shows an unconfirmed proposal or its review task to a teacher', function () {
    ['director' => $director, 'teacher' => $teacher, 'student' => $student, 'group' => $group] = proposalScenario();
    $proposal = proposeAs($director, $student);

    expect(Accommodation::query()->visibleTo($teacher)->exists())->toBeFalse()
        ->and($proposal->isVisibleTo($teacher))->toBeFalse()
        ->and(Accommodation::query()->effective()->exists())->toBeFalse();

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/groups/{$group->id}/scheduled-follow-ups")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/students/{$student->id}/tracking")
        ->assertOk()
        ->assertJsonCount(0, 'data.support_chips.accommodations');
});

it('lets psychopedagogy edit and confirm a proposal: it reaches the teacher and the task closes', function () {
    ['director' => $director, 'psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();
    $proposal = proposeAs($director, $student);

    Sanctum::actingAs($psycho);
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['type' => 'Tiempo extendido']))
        ->assertOk()
        ->assertJsonPath('data.review_status', 'pending');

    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/confirm", ['starts_on' => '2026-10-01'])
        ->assertOk()
        ->assertJsonPath('data.review_status', 'confirmed')
        ->assertJsonPath('data.reviewed_by_id', $psycho->id)
        ->assertJsonPath('data.starts_on', '2026-10-01')
        ->assertJsonPath('data.type', 'Tiempo extendido')
        ->assertJsonPath('data.is_effective', true);

    expect($proposal->refresh()->isVisibleTo($teacher))->toBeTrue()
        ->and(ScheduledFollowUp::query()->sole()->resolved)->toBeTrue()
        ->and($proposal->reviewEvents()->pluck('action')->map->value->all())
        ->toBe(['submitted', 'edited', 'confirmed']);
});

it('keeps the same proposal through a return, a correction and a resubmission', function () {
    Notification::fake();
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    $proposal = proposeAs($director, $student);

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/return", [])
        ->assertUnprocessable()->assertJsonValidationErrorFor('note');
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/return", ['note' => '¿En qué materias?'])
        ->assertOk()
        ->assertJsonPath('data.review_status', 'returned')
        ->assertJsonPath('data.review_note', '¿En qué materias?');
    Notification::assertSentTo($director, AccommodationProposalReturnedNotification::class);

    Sanctum::actingAs($director);
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['description' => 'Solo en escritos de Matemática.']))
        ->assertOk();
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/resubmit", ['note' => 'Corregido'])
        ->assertOk()
        ->assertJsonPath('data.review_status', 'pending');

    expect(Accommodation::query()->count())->toBe(1)
        ->and(ScheduledFollowUp::query()->count())->toBe(1)
        ->and(ScheduledFollowUp::query()->sole()->resolved)->toBeFalse();

    $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")
        ->assertOk()
        ->assertJsonPath('data.*.action', ['submitted', 'returned', 'edited', 'resubmitted'])
        ->assertJsonPath('data.1.note', '¿En qué materias?')
        ->assertJsonPath('data.1.user.name', 'Ana Psico');
});

it('lets direction withdraw a pending or returned proposal with a reason, keeping it for the record', function () {
    ['director' => $director, 'student' => $student] = proposalScenario();
    $proposal = proposeAs($director, $student);
    $proposal->update(['review_status' => AccommodationReviewStatus::Returned]);

    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/withdraw", [])
        ->assertUnprocessable()->assertJsonValidationErrorFor('reason');
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/withdraw", ['reason' => 'Ya no hace falta'])
        ->assertOk()
        ->assertJsonPath('data.review_status', 'withdrawn')
        ->assertJsonPath('data.reviewed_by_id', $director->id)
        ->assertJsonPath('data.review_note', 'Ya no hace falta');

    expect(Accommodation::query()->whereKey($proposal->id)->exists())->toBeTrue()
        ->and(ScheduledFollowUp::query()->sole()->resolved)->toBeTrue();

    // Closed: no further edits or steps.
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload())->assertForbidden();
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/resubmit")->assertUnprocessable();
});

it('lets psychopedagogy reject a proposal with a mandatory reason direction can read', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    $proposal = proposeAs($director, $student);

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/reject", [])
        ->assertUnprocessable()->assertJsonValidationErrorFor('reason');
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/reject", ['reason' => 'No corresponde'])
        ->assertOk()
        ->assertJsonPath('data.review_status', 'rejected');

    expect(ScheduledFollowUp::query()->sole()->resolved)->toBeTrue();

    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload())->assertUnprocessable();
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/confirm")->assertUnprocessable();

    Sanctum::actingAs($director);
    $this->getJson("/api/v1/students/{$proposal->student_id}/accommodation-proposals")
        ->assertOk()
        ->assertJsonPath('data.0.review_status', 'rejected')
        ->assertJsonPath('data.0.review_note', 'No corresponde');
});

it('keeps each step to its role', function () {
    ['director' => $director, 'psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();
    $proposal = proposeAs($director, $student);

    // Direction never confirms, returns or rejects.
    foreach (['confirm', 'return', 'reject'] as $step) {
        $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/{$step}", ['note' => 'x', 'reason' => 'x'])
            ->assertForbidden();
    }
    // Direction never switches it on or off.
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['active' => false]))
        ->assertUnprocessable()->assertJsonValidationErrorFor('active');

    Sanctum::actingAs($psycho);
    foreach (['resubmit', 'withdraw'] as $step) {
        $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/{$step}", ['reason' => 'x'])->assertForbidden();
    }

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")->assertForbidden();
    $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")->assertForbidden();
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/confirm")->assertForbidden();
});

it('does not let direction edit or switch off a confirmed accommodation', function () {
    ['director' => $director, 'student' => $student] = proposalScenario();
    Tenancy::useSchool($student->school);
    $confirmed = Accommodation::factory()->create(['student_id' => $student->id]);

    Sanctum::actingAs($director);
    $this->patchJson("/api/v1/accommodations/{$confirmed->id}", proposalPayload(['active' => false]))
        ->assertForbidden();
});

it('does not let the review task be resolved by hand', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    proposeAs($director, $student);
    $followUp = ScheduledFollowUp::query()->sole();

    Sanctum::actingAs($psycho);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')
        ->assertOk()
        ->assertJsonPath('data.0.accommodation_id', $followUp->accommodation_id);
    $this->postJson("/api/v1/scheduled-follow-ups/{$followUp->id}/resolve", ['resolution_note' => 'ok'])
        ->assertForbidden();

    // Nor managed by hand through the stages model (ClickUp 86e3qkwna): no
    // stages, no people changes, no leaving it — the circuit closes it.
    $this->postJson("/api/v1/scheduled-follow-ups/{$followUp->id}/stages", [
        'description' => 'x', 'responsible_id' => $psycho->id, 'due_date' => now()->addWeek()->toDateString(),
    ])->assertForbidden();
    $this->postJson("/api/v1/scheduled-follow-ups/{$followUp->id}/general-responsibles", ['user_id' => $psycho->id])
        ->assertForbidden();
    $this->postJson("/api/v1/scheduled-follow-ups/{$followUp->id}/withdraw", ['reason' => 'x'])
        ->assertForbidden();
    $this->getJson("/api/v1/scheduled-follow-ups/{$followUp->id}")
        ->assertOk()
        ->assertJsonPath('data.can.resolve', false);
});

it('lists the returned-proposal notice to the proposer', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    $proposal = proposeAs($director, $student);

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/return", ['note' => 'Revisar'])->assertOk();

    Sanctum::actingAs($director);
    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.data.type', 'accommodation_proposal_returned')
        ->assertJsonPath('data.0.data.accommodation_id', $proposal->id)
        // Ids only: never the comment.
        ->assertJsonMissing(['note' => 'Revisar']);
});

it('isolates proposals by school', function () {
    ['director' => $director, 'student' => $student] = proposalScenario();
    $proposal = proposeAs($director, $student);
    $otherSchool = School::factory()->create();
    $otherPsycho = User::factory()->forSchool($otherSchool)->psychopedagogue()->create();

    Sanctum::actingAs($otherPsycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/confirm")->assertNotFound();
    $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")->assertNotFound();
});

it('lists a student\'s proposals, open first, and drops a confirmed one from the list', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    $withdrawn = proposeAs($director, $student, ['type' => 'Retirada']);
    $this->postJson("/api/v1/accommodations/{$withdrawn->id}/proposal/withdraw", ['reason' => 'x'])->assertOk();
    $open = proposeAs($director, $student, ['type' => 'Abierta']);
    $confirmed = proposeAs($director, $student, ['type' => 'Confirmada']);

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$confirmed->id}/proposal/confirm")->assertOk();

    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertOk()
        ->assertJsonPath('data.*.id', [$open->id, $withdrawn->id]);
});

it('asks direction for the review deadline and who reviews the proposal', function () {
    ['director' => $director, 'student' => $student] = proposalScenario();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['review_due_date', 'review_recipients']);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(
        // School calendar day, as the rule: UTC's yesterday is the school's today
        // late in the evening in Montevideo.
        reviewRouting(['review_due_date' => SchoolTime::today()->subDay()->toDateString()]),
    ))->assertUnprocessable()->assertJsonValidationErrors(['review_due_date']);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(
        reviewRouting(['review_recipients' => 'people']),
    ))->assertUnprocessable()->assertJsonValidationErrors(['reviewer_ids']);

    expect(Accommodation::query()->count())->toBe(0);
});

it('sends the proposal only to the psychopedagogues direction picked', function () {
    Notification::fake();
    ['school' => $school, 'director' => $director, 'psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();
    $second = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Beatriz Psico']);
    $third = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Carla Psico']);
    $foreign = User::factory()->forSchool(School::factory()->create())->psychopedagogue()->create();
    Sanctum::actingAs($director);

    // Only psychopedagogues of the school can be picked.
    foreach ([$teacher->id, $foreign->id] as $invalid) {
        $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(
            reviewRouting(['review_recipients' => 'people', 'reviewer_ids' => [$invalid]]),
        ))->assertUnprocessable()->assertJsonValidationErrors(['reviewer_ids.0']);
    }

    proposeAs($director, $student, ['review_recipients' => 'people', 'reviewer_ids' => [$second->id, $third->id]]);

    $followUp = ScheduledFollowUp::query()->sole();
    expect($followUp->assigned_to_id)->toBeNull()
        ->and($followUp->sharedWith()->orderBy('users.id')->pluck('users.id')->all())->toBe([$second->id, $third->id]);
    Notification::assertSentTo([$second, $third], FollowUpAssignedNotification::class);
    Notification::assertNotSentTo($psycho, FollowUpAssignedNotification::class);

    Sanctum::actingAs($second);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(1, 'data');
    Sanctum::actingAs($psycho);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(0, 'data');
});

it('sends the proposal to every psychopedagogue when direction picks the whole role', function () {
    Notification::fake();
    ['school' => $school, 'director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    $second = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Beatriz Psico']);

    proposeAs($director, $student);

    expect(ScheduledFollowUp::query()->sole()->sharedWith()->orderBy('users.id')->pluck('users.id')->all())
        ->toBe([$psycho->id, $second->id]);
    Notification::assertSentTo([$psycho, $second], FollowUpAssignedNotification::class);
});

it('lets only psychopedagogy write «Origen», and never shows it to a teacher', function () {
    ['director' => $director, 'psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();

    // Direction never sends it, neither proposing nor editing its proposal.
    Sanctum::actingAs($director);
    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(
        [...reviewRouting(), 'origin' => 'Informe 2024'],
    ))->assertUnprocessable()->assertJsonValidationErrors(['origin']);
    $proposal = proposeAs($director, $student);
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['origin' => 'Informe 2024']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['origin']);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertOk()
        ->assertJsonPath('data.0.origin', null)
        ->assertJsonPath('data.0.proposed_by_name', 'Directora');

    // Psychopedagogy fills it in on confirming.
    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/confirm", ['origin' => 'Propuesta de Directora'])
        ->assertOk()
        ->assertJsonPath('data.origin', 'Propuesta de Directora')
        ->assertJsonPath('data.reviewed_by_name', 'Ana Psico');

    // ...and when creating or editing one directly.
    $direct = $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(['origin' => 'Registrado por Psicopedagogía']))
        ->assertCreated()
        ->assertJsonPath('data.origin', 'Registrado por Psicopedagogía')
        ->json('data.id');
    $this->patchJson("/api/v1/accommodations/{$direct}", proposalPayload(['origin' => 'Informe neuropsicológico 2024']))
        ->assertOk()
        ->assertJsonPath('data.origin', 'Informe neuropsicológico 2024');

    // The teacher's chips never carry it.
    Sanctum::actingAs($teacher);
    $chips = $this->getJson("/api/v1/students/{$student->id}/tracking")
        ->assertOk()
        ->json('data.support_chips.accommodations');
    expect($chips)->not->toBeEmpty();
    foreach ($chips as $chip) {
        expect($chip)->not->toHaveKey('origin');
    }
});

it('does not let a step overwrite one a concurrent request already took', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    $proposal = proposeAs($director, $student);
    // The copy route-model binding loaded before the other step committed.
    $stale = Accommodation::query()->findOrFail($proposal->id);

    // Meanwhile direction withdraws it.
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/withdraw", ['reason' => 'Ya no hace falta'])->assertOk();

    expect(fn () => app(AccommodationProposalWorkflow::class)->confirm($stale, $psycho, null))
        ->toThrow(ValidationException::class);

    expect($proposal->refresh()->review_status)->toBe(AccommodationReviewStatus::Withdrawn)
        ->and($proposal->isEffective())->toBeFalse()
        ->and($proposal->reviewEvents()->pluck('action')->map->value->all())->toBe(['submitted', 'withdrawn']);
});

it('does not let a direction edit land on a proposal confirmed while it was in flight', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    $proposal = proposeAs($director, $student);

    // Psychopedagogy confirms it right after route-model binding loaded the
    // (still pending) copy the director's request is authorized against.
    $confirmedMeanwhile = false;
    Accommodation::retrieved(function (Accommodation $retrieved) use (&$confirmedMeanwhile, $proposal, $psycho): void {
        if ($confirmedMeanwhile || $retrieved->id !== $proposal->id) {
            return;
        }
        $confirmedMeanwhile = true;
        DB::table('accommodations')->where('id', $proposal->id)->update([
            'review_status' => AccommodationReviewStatus::Confirmed->value,
            'reviewed_by_id' => $psycho->id,
            'reviewed_at' => now(),
        ]);
    });

    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['type' => 'Cambiado por Dirección']))
        ->assertForbidden();

    expect($confirmedMeanwhile)->toBeTrue()
        ->and($proposal->refresh()->type)->toBe('Más tiempo en escritos');
});

it('leaves «Vigente desde» of a proposal to psychopedagogy, on the server too', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(
        [...reviewRouting(), 'starts_on' => now()->addMonth()->toDateString()],
    ))->assertUnprocessable()->assertJsonValidationErrors(['starts_on']);

    $proposal = proposeAs($director, $student);
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['starts_on' => now()->addMonth()->toDateString()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_on']);

    // Psychopedagogy may set it while reviewing.
    Sanctum::actingAs($psycho);
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['starts_on' => '2026-11-02']))
        ->assertOk()
        ->assertJsonPath('data.starts_on', '2026-11-02');
});

it('does not let a teacher write «Origen»', function () {
    ['teacher' => $teacher, 'student' => $student] = proposalScenario();
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(['origin' => 'Informe 2024']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['origin']);
});

it('refuses a proposal sent to the whole role when there is no active psychopedagogue', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    $psycho->update(['disabled_at' => now()]);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(reviewRouting()))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['review_recipients']);

    expect(Accommodation::query()->count())->toBe(0)
        ->and(ScheduledFollowUp::query()->count())->toBe(0);
});

it('records which fields each edit changed, and skips an edit that changed nothing', function () {
    ['director' => $director, 'student' => $student] = proposalScenario();
    Tenancy::useSchool($student->school);
    $subject = Subject::factory()->create(['school_id' => $student->school_id]);
    $proposal = proposeAs($director, $student);

    // Same values: not a step of the history.
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload())->assertOk();
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload([
        'description' => 'Solo en escritos de Matemática.',
        'subject_ids' => [$subject->id],
    ]))->assertOk();

    $history = $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")
        ->assertOk()
        ->assertJsonPath('data.*.action', ['submitted', 'edited'])
        ->assertJsonPath('data.1.changed_fields', ['description', 'subject_ids']);
    // Names only, never the content.
    expect($history->getContent())->not->toContain('Solo en escritos');
});

it('lists proposals with a fixed number of queries', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();

    $countFor = function () use ($director, $student): int {
        Sanctum::actingAs($director);
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson("/api/v1/students/{$student->id}/accommodation-proposals")->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $returned = proposeAs($director, $student);
    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$returned->id}/proposal/return", ['note' => 'Revisar'])->assertOk();
    $rejectOne = function (int $i) use ($director, $psycho, $student): void {
        $more = proposeAs($director, $student, ['type' => "Otra {$i}"]);
        Sanctum::actingAs($psycho);
        test()->postJson("/api/v1/accommodations/{$more->id}/proposal/reject", ['reason' => 'No corresponde'])->assertOk();
    };
    $rejectOne(0);
    $few = $countFor();

    foreach (range(1, 3) as $i) {
        $rejectOne($i);
    }

    expect($countFor())->toBe($few);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.review_note', 'Revisar')
        ->assertJsonPath('data.0.proposed_by_name', 'Directora')
        ->assertJsonPath('data.1.review_note', 'No corresponde')
        ->assertJsonPath('data.1.reviewed_by_name', 'Ana Psico');
});

it('dates the activation of a confirmed proposal on its confirmation, not on its proposal', function () {
    ['director' => $director, 'psycho' => $psycho, 'student' => $student, 'group' => $group] = proposalScenario();
    $this->travelTo('2026-04-01 10:00:00');
    $proposal = proposeAs($director, $student);
    $this->travelTo('2026-04-08 10:00:00');
    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/confirm")->assertOk();
    $this->travelBack();

    $groupActivations = collect($this->getJson("/api/v1/groups/{$group->id}/performance-timeline")->assertOk()->json('marks'))
        ->where('type', 'accommodation_activated')
        ->pluck('date')
        ->values()
        ->all();
    expect($groupActivations)->toBe(['2026-04-08']);

    $studentActivations = collect($this->getJson("/api/v1/students/{$student->id}/performance-timeline")->assertOk()->json('marks'))
        ->where('type', 'accommodation_activated')
        ->pluck('date')
        ->map(fn (string $date) => substr($date, 0, 10))
        ->values()
        ->all();
    expect($studentActivations)->toBe(['2026-04-08']);
});
