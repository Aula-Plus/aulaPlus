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

it('shows «Lo que observaste» on the proposal card, to psychopedagogy and to its proposer', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();
    $proposal = teacherProposes($teacher, $student, ['observation' => 'Resuelve mejor con un paso por vez.']);

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")
        ->assertOk()->assertJsonPath('data.0.observation', 'Resuelve mejor con un paso por vez.');

    Sanctum::actingAs($psycho);
    $this->getJson('/api/v1/accommodation-inbox')
        ->assertOk()
        ->assertJsonFragment(['id' => $proposal->id, 'observation' => 'Resuelve mejor con un paso por vez.']);

    // Still the first step's note after a return and a resubmission.
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/return", ['note' => 'Decí en qué materias.'])->assertOk();
    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/resubmit", ['note' => 'Listo.'])
        ->assertOk()->assertJsonPath('data.observation', 'Resuelve mejor con un paso por vez.');
});

it('never tells the teacher in the history who reviewed their proposal', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();
    $proposal = teacherProposes($teacher, $student);

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/reject", ['reason' => 'No corresponde.'])->assertOk();
    $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")
        ->assertOk()
        ->assertJsonPath('data.1.user.name', 'Ana Psico')
        ->assertJsonPath('data.1.actor_hidden', false);

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")
        ->assertOk()
        ->assertJsonPath('data.0.action', 'submitted')
        ->assertJsonPath('data.0.user.id', $teacher->id)
        ->assertJsonPath('data.1.action', 'rejected')
        ->assertJsonPath('data.1.user', null)
        ->assertJsonPath('data.1.actor_hidden', true)
        ->assertDontSee('Ana Psico');
});

it('says who proposed it in the review task detail', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = proposalScenario();
    teacherProposes($teacher, $student);
    $followUp = ScheduledFollowUp::query()->sole();

    Sanctum::actingAs($psycho);
    $this->getJson("/api/v1/scheduled-follow-ups/{$followUp->id}")
        ->assertOk()->assertJsonPath('data.accommodation_proposed_as', 'teacher');
});

it('hides a teacher proposal from a teacher of another school', function () {
    ['teacher' => $teacher, 'student' => $student] = proposalScenario();
    $proposal = teacherProposes($teacher, $student);
    $outsider = User::factory()->forSchool(School::factory()->create())->teacher()->create();

    Sanctum::actingAs($outsider);
    $this->getJson("/api/v1/students/{$student->id}/accommodation-proposals")->assertNotFound();
    $this->getJson("/api/v1/accommodations/{$proposal->id}/proposal/history")->assertNotFound();
    $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload(reviewRouting()))->assertNotFound();
});

// ── «Materias donde aplica» of a teacher's proposal (answer 2B, 2026-11-26) ─

it('lets a teacher restrict a proposal only to the subjects they give the student, or to none', function () {
    ['school' => $school, 'psycho' => $psycho, 'teacher' => $teacher, 'group' => $group, 'student' => $student] = proposalScenario();
    $mine = $group->teachers()->wherePivot('teacher_id', $teacher->id)->first()->pivot->subject_id;
    $colleagues = leadGroup($group, User::factory()->forSchool($school)->teacher()->create());
    // A subject the teacher gives in another group, not this student's.
    $elsewhere = leadGroup(Group::factory()->create(['school_id' => $school->id]), $teacher);

    Sanctum::actingAs($teacher);
    foreach ([$colleagues->id, $elsewhere->id] as $notMine) {
        $this->postJson("/api/v1/students/{$student->id}/accommodations", proposalPayload([...reviewRouting(), 'subject_ids' => [$notMine]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('subject_ids.0');
    }
    expect(Accommodation::query()->count())->toBe(0);

    // «Todas las materias» (none chosen) and their own subject both go through.
    teacherProposes($teacher, $student, ['subject_ids' => []]);
    $proposal = teacherProposes($teacher, $student, ['subject_ids' => [$mine]]);
    expect($proposal->subjects()->pluck('subjects.id')->all())->toBe([$mine]);

    // Resending it keeps to their subjects too.
    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/return", ['note' => 'Revisá las materias.'])->assertOk();
    Sanctum::actingAs($teacher);
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['subject_ids' => [$mine, $colleagues->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('subject_ids.1');
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['subject_ids' => [$mine]]))->assertOk();
    $this->postJson("/api/v1/accommodations/{$proposal->id}/proposal/resubmit")->assertOk();

    // Psychopedagogy may change them to any subject of the school when confirming.
    Sanctum::actingAs($psycho);
    $this->patchJson("/api/v1/accommodations/{$proposal->id}", proposalPayload(['subject_ids' => [$colleagues->id]]))->assertOk();
    expect($proposal->subjects()->pluck('subjects.id')->all())->toBe([$colleagues->id]);
});

it('lists the subjects the teacher gives the student, and only to who can open the file', function () {
    ['school' => $school, 'psycho' => $psycho, 'teacher' => $teacher, 'group' => $group, 'student' => $student] = proposalScenario();
    $mine = $group->teachers()->wherePivot('teacher_id', $teacher->id)->first()->pivot->subject_id;
    leadGroup($group, User::factory()->forSchool($school)->teacher()->create());
    leadGroup(Group::factory()->create(['school_id' => $school->id]), $teacher);

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/taught-subjects")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine);

    // A teacher who does not teach the student: 403; another school: 404.
    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());
    $this->getJson("/api/v1/students/{$student->id}/taught-subjects")->assertForbidden();
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->teacher()->create());
    $this->getJson("/api/v1/students/{$student->id}/taught-subjects")->assertNotFound();

    // Psychopedagogy gives no subject: the list is empty (it picks from the catalog).
    Sanctum::actingAs($psycho);
    $this->getJson("/api/v1/students/{$student->id}/taught-subjects")->assertOk()->assertJsonCount(0, 'data');
});
