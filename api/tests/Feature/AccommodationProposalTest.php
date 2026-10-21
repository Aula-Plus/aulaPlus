<?php

use App\Enums\AccommodationReviewStatus;
use App\Models\Accommodation;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AccommodationProposalReturnedNotification;
use App\Notifications\FollowUpAssignedNotification;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;
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

function proposeAs(User $director, Student $student, array $overrides = []): Accommodation
{
    Sanctum::actingAs($director);
    $id = test()->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload($overrides))
        ->assertCreated()
        ->json('data.id');

    return Accommodation::query()->findOrFail($id);
}

it('turns what direction creates into a pending proposal with a review task for psychopedagogy', function () {
    Notification::fake();
    ['director' => $director, 'psycho' => $psycho, 'student' => $student] = proposalScenario();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(['active' => false]))
        ->assertCreated()
        ->assertJsonPath('data.review_status', 'pending')
        ->assertJsonPath('data.proposed_by_id', $director->id)
        ->assertJsonPath('data.is_effective', false)
        ->assertJsonPath('data.active', true);

    $accommodation = Accommodation::query()->sole();
    $followUp = ScheduledFollowUp::query()->sole();
    expect($followUp->accommodation_id)->toBe($accommodation->id)
        ->and($followUp->assigned_to_id)->toBe($psycho->id)
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
