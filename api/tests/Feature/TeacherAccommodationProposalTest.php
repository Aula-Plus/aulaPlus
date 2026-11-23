<?php

use App\Enums\AccommodationReviewStatus;
use App\Models\Accommodation;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AccommodationProposalReturnedNotification;
use App\Notifications\FollowUpAssignedNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * ClickUp 1dfd3mc55zu (PA-002): a teacher proposes an accommodation from the
 * student file, through the same circuit as direction. Until psychopedagogy
 * confirms or rejects it, only the teacher who proposed it and psychopedagogy
 * see it (P06-AJU-02): never direction, never another teacher.
 */
function teacherProposes(User $teacher, Student $student, array $overrides = []): Accommodation
{
    Sanctum::actingAs($teacher);
    $id = test()->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload([...reviewRouting(), ...$overrides]))
        ->assertCreated()
        ->json('data.id');

    return Accommodation::query()->findOrFail($id);
}

it('turns what a teacher creates into a pending proposal, never an accommodation in force', function () {
    Notification::fake();
    ['psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();

    $proposal = teacherProposes($teacher, $student, ['observation' => 'Le cuesta terminar los escritos a tiempo.']);

    expect($proposal->review_status)->toBe(AccommodationReviewStatus::Pending)
        ->and($proposal->proposed_by_id)->toBe($teacher->id)
        ->and($proposal->proposed_as)->toBe('teacher')
        ->and($proposal->isEffective())->toBeFalse()
        ->and($proposal->reviewEvents()->sole()->note)->toBe('Le cuesta terminar los escritos a tiempo.');

    $followUp = ScheduledFollowUp::query()->sole();
    expect($followUp->accommodation_id)->toBe($proposal->id);
    Notification::assertSentTo($psycho, FollowUpAssignedNotification::class);

    // It is not among the teacher's accommodations in force.
    expect(Accommodation::query()->visibleTo($teacher)->exists())->toBeFalse();
});

it('requires the review routing from a teacher and never takes «Origen» or «Vigente desde»', function () {
    ['teacher' => $teacher, 'student' => $student] = proposalScenario();
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('review_due_date');

    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload([
        ...reviewRouting(),
        'origin' => 'Lo vi yo',
        'starts_on' => now()->toDateString(),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['origin', 'starts_on']);

    expect(Accommodation::query()->count())->toBe(0);
});

it('does not let a teacher propose for a student they do not teach', function () {
    ['school' => $school, 'teacher' => $teacher] = proposalScenario();
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);
    $stranger = Student::factory()->create(['school_id' => $school->id]);
    $stranger->groups()->attach($otherGroup, ['school_year' => now()->year]);

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/students/{$stranger->id}/accommodations", proposalPayload(reviewRouting()))
        ->assertForbidden();

    expect(Accommodation::query()->count())->toBe(0);
});

it('shows a pending teacher proposal only to its proposer and psychopedagogy', function () {
    ['school' => $school, 'director' => $director, 'psycho' => $psycho, 'teacher' => $teacher, 'group' => $group, 'student' => $student] = proposalScenario();
    $colleague = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $colleague);
    $proposal = teacherProposes($teacher, $student);
    $followUp = ScheduledFollowUp::query()->sole();

    // The proposer sees it in their list, without review fields.
    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $proposal->id)
        ->assertJsonPath('data.0.proposed_as', 'teacher')
        ->assertJsonPath('data.0.origin', null);
    $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")->assertOk();

    // Psychopedagogy sees it, and its review task.
    Sanctum::actingAs($psycho);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertOk()->assertJsonCount(1, 'data');
    expect($psycho->can('view', $followUp))->toBeTrue();

    // Direction sees neither the proposal, nor its history, nor its review task.
    Sanctum::actingAs($director);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")->assertForbidden();
    $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups")
        ->assertOk()->assertJsonCount(0, 'data');
    expect($director->can('view', $proposal))->toBeFalse()
        ->and($director->can('view', $followUp))->toBeFalse();
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/withdraw", ['reason' => 'x'])->assertForbidden();
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", ['type' => 'Otro'])->assertForbidden();

    // Another teacher of the group sees nothing of it.
    Sanctum::actingAs($colleague);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")->assertForbidden();
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/withdraw", ['reason' => 'x'])->assertForbidden();
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", ['type' => 'Otro'])->assertForbidden();
});

it('lets psychopedagogy return it, the teacher correct and resubmit it, and psychopedagogy confirm it', function () {
    Notification::fake();
    ['director' => $director, 'psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();
    $proposal = teacherProposes($teacher, $student);

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/return", ['note' => 'Decí en qué evaluaciones.'])
        ->assertOk()->assertJsonPath('data.review_status', 'returned');
    Notification::assertSentTo($teacher, AccommodationProposalReturnedNotification::class);

    // The teacher reads why, corrects it and sends it back.
    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertJsonPath('data.0.review_note', 'Decí en qué evaluaciones.');
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['description' => 'Media hora extra en escritos de Lengua.']))
        ->assertOk();
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/resubmit")
        ->assertOk()->assertJsonPath('data.review_status', 'pending');
    // The teacher never confirms their own proposal.
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/confirm")->assertForbidden();

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/confirm")
        ->assertOk()->assertJsonPath('data.review_status', 'confirmed');

    // In force: the teacher gets it like any other accommodation, and
    // direction sees it now.
    $proposal->refresh();
    expect($proposal->isEffective())->toBeTrue()
        ->and($proposal->isVisibleTo($teacher))->toBeTrue()
        ->and($proposal->isVisibleTo($director))->toBeTrue()
        ->and(ScheduledFollowUp::query()->sole()->resolved)->toBeTrue();
});

it('lets the teacher withdraw their own proposal, which never becomes public', function () {
    ['director' => $director, 'teacher' => $teacher, 'student' => $student] = proposalScenario();
    $proposal = teacherProposes($teacher, $student);

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/withdraw", ['reason' => 'Ya no hace falta.'])
        ->assertOk()->assertJsonPath('data.review_status', 'withdrawn');

    expect($proposal->refresh()->isVisibleTo($director))->toBeFalse();
});

it('makes a rejected teacher proposal visible to direction', function () {
    ['director' => $director, 'psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();
    $proposal = teacherProposes($teacher, $student);

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/reject", ['reason' => 'No corresponde.'])->assertOk();

    Sanctum::actingAs($director);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.review_status', 'rejected');
});

it('records the proposals direction made before as direction proposals', function () {
    ['director' => $director, 'student' => $student] = proposalScenario();

    expect(proposeAs($director, $student)->proposed_as)->toBe('director');
});
