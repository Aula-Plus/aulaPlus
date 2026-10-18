<?php

use App\Models\AuditLog;
use App\Models\FollowUpStage;
use App\Models\FollowUpStageNote;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Follow-ups with stages, several people and history (ClickUp 86e3qkwna).
 */

/**
 * @return array{school: School, student: Student, director: User, psico: User, teacher: User, followUpId: int}
 */
function followUpScenario(): array
{
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psico = User::factory()->forSchool($school)->psychopedagogue()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    Sanctum::actingAs($director);
    $followUpId = test()->postJson("/api/v1/students/{$student->id}/scheduled-follow-ups", [
        'title' => 'Ajuste de lectura',
        'description' => 'Revisar si el ajuste de lectura sigue haciendo falta',
        'due_date' => now()->addDays(7)->toDateString(),
        'assigned_to_id' => $psico->id,
    ])->assertCreated()->json('data.id');

    return compact('school', 'student', 'director', 'psico', 'teacher', 'followUpId');
}

function firstStageId(int $followUpId): int
{
    return FollowUpStage::query()->where('scheduled_follow_up_id', $followUpId)->orderBy('id')->value('id');
}

it('runs the whole flow: split, extra stage, deadline, closures, resolve and automatic reopening', function () {
    ['director' => $director, 'psico' => $psico, 'teacher' => $teacher, 'followUpId' => $id] = followUpScenario();
    $url = "/api/v1/scheduled-follow-ups/{$id}";

    // Created with one stage from the creation data; psico is the general
    // responsible and was notified, direction takes part as participant.
    Sanctum::actingAs($psico);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.title', 'Ajuste de lectura')
        ->assertJsonCount(1, 'data.stages')
        ->assertJsonPath('data.stages.0.responsible.id', $psico->id)
        ->assertJsonPath('data.general_responsibles.0.id', $psico->id)
        ->assertJsonPath('data.participants.0.id', $director->id)
        ->assertJsonPath('data.can.resolve', false);
    expect($psico->unreadNotifications()->count())->toBe(1);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonPath('data.0.id', $id);

    // Psico splits the stage in two: one for herself, one for the teacher.
    $original = firstStageId($id);
    $this->postJson("/api/v1/follow-up-stages/{$original}/split", ['stages' => [
        ['description' => 'Observar en clase', 'responsible_id' => $psico->id, 'due_date' => now()->addDays(5)->toDateString()],
        ['description' => 'Registrar lectura en voz alta', 'responsible_id' => $teacher->id, 'due_date' => now()->addDays(7)->toDateString()],
    ]])->assertOk()->assertJsonCount(3, 'data.stages')->assertJsonPath('data.stages.0.status', 'split');

    // ...and adds a meeting with direction, later than everything else.
    $meetingDate = now()->addDays(14)->toDateString();
    $this->postJson("{$url}/stages", [
        'description' => 'Reunión con la familia',
        'responsible_id' => $director->id,
        'due_date' => $meetingDate,
    ])->assertOk()->assertJsonPath('data.due_date', $meetingDate);

    $followUp = ScheduledFollowUp::with('stages')->findOrFail($id);
    [, $psicoStage, $teacherStage, $meetingStage] = $followUp->stages->all();
    expect($followUp->due_date->toDateString())->toBe($meetingDate)
        ->and($teacher->unreadNotifications()->first()->data['assigned_as'])->toBe('stage_responsible')
        ->and($followUp->events()->where('type', 'deadline_changed')->first()->data)
        ->toBe(['from' => now()->addDays(7)->toDateString(), 'to' => $meetingDate]);

    // The teacher works on their own stage, but not on someone else's.
    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/follow-up-stages/{$teacherStage->id}/notes", ['body' => 'Leyó con apoyo visual'])->assertOk();
    $this->patchJson("/api/v1/follow-up-stages/{$psicoStage->id}", ['due_date' => now()->addDays(30)->toDateString()])->assertForbidden();
    $this->postJson("/api/v1/follow-up-stages/{$teacherStage->id}/close", ['conclusion' => 'Mejoró la fluidez'])->assertOk();

    // Cannot resolve while stages are pending.
    Sanctum::actingAs($psico);
    $this->postJson("{$url}/resolve", ['resolution_note' => 'Listo'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Every stage must be closed before resolving.');
    $this->postJson("/api/v1/follow-up-stages/{$psicoStage->id}/close", ['conclusion' => 'Observado'])->assertOk();
    Sanctum::actingAs($director);
    $this->postJson("/api/v1/follow-up-stages/{$meetingStage->id}/close", ['conclusion' => 'Familia de acuerdo'])->assertOk();

    // Direction is a participant, not a general responsible: cannot resolve.
    $this->postJson("{$url}/resolve", ['resolution_note' => 'Listo'])->assertForbidden();
    Sanctum::actingAs($psico);
    $this->postJson("{$url}/resolve", ['resolution_note' => 'Se mantiene el ajuste'])
        ->assertOk()->assertJsonPath('data.resolved', true);

    // A stage responsible adds a new pending stage: it reopens on its own.
    Sanctum::actingAs($teacher);
    $this->postJson("{$url}/stages", [
        'description' => 'Volver a medir en marzo',
        'responsible_id' => $teacher->id,
        'due_date' => now()->addDays(60)->toDateString(),
    ])->assertOk()
        ->assertJsonPath('data.resolved', false)
        ->assertJsonPath('data.resolution_note', null)
        ->assertJsonPath('data.due_date', now()->addDays(60)->toDateString());

    // History is complete; earlier closures and the conclusion are kept.
    $followUp->refresh()->load('stages', 'events');
    expect($followUp->stages->pluck('status')->map->value->all())->toBe(['split', 'done', 'done', 'done', 'pending'])
        ->and($followUp->stages[2]->conclusion)->toBe('Mejoró la fluidez')
        ->and($followUp->events->pluck('type')->map->value->all())->toContain('created', 'stage_split', 'stage_added', 'stage_closed', 'deadline_changed', 'resolved', 'reopened')
        ->and($followUp->events->firstWhere('type.value', 'resolved')->note)->toBe('Se mantiene el ajuste')
        ->and($followUp->events->firstWhere('type.value', 'reopened')->note)->toBe('Se mantiene el ajuste')
        ->and(FollowUpStageNote::count())->toBe(1);

    // Everyone involved sees the same record.
    Sanctum::actingAs($director);
    $this->getJson($url)->assertOk()->assertJsonCount(5, 'data.stages')->assertJsonPath('data.stages.1.notes', []);
    $this->getJson($url)->assertJsonPath('data.stages.2.notes.0.body', 'Leyó con apoyo visual');
});

it('lets a participant only view: no stage edits, notes, stages or participants', function () {
    ['psico' => $psico, 'director' => $director, 'teacher' => $teacher, 'followUpId' => $id] = followUpScenario();
    $stageId = firstStageId($id);

    // Direction created it and is a plain participant.
    Sanctum::actingAs($director);
    $this->patchJson("/api/v1/follow-up-stages/{$stageId}", ['description' => 'Otra cosa'])->assertForbidden();
    $this->postJson("/api/v1/follow-up-stages/{$stageId}/notes", ['body' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/follow-up-stages/{$stageId}/close", ['conclusion' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/follow-up-stages/{$stageId}/split", ['stages' => [
        ['description' => 'a', 'responsible_id' => $director->id, 'due_date' => now()->toDateString()],
        ['description' => 'b', 'responsible_id' => $director->id, 'due_date' => now()->toDateString()],
    ]])->assertForbidden();
    $this->postJson("/api/v1/scheduled-follow-ups/{$id}/stages", [
        'description' => 'x', 'responsible_id' => $director->id, 'due_date' => now()->toDateString(),
    ])->assertForbidden();
    $this->postJson("/api/v1/scheduled-follow-ups/{$id}/participants", ['user_id' => $teacher->id])->assertForbidden();
    $this->postJson("/api/v1/scheduled-follow-ups/{$id}/participants/{$psico->id}/remove")->assertForbidden();

    $this->getJson("/api/v1/scheduled-follow-ups/{$id}")->assertOk()
        ->assertJsonPath('data.can.add_stage', false)
        ->assertJsonPath('data.stages.0.can.update', false)
        ->assertJsonPath('data.can.withdraw', true);
});

it('lets only general responsibles add or remove general responsibles', function () {
    ['psico' => $psico, 'director' => $director, 'teacher' => $teacher, 'followUpId' => $id] = followUpScenario();
    $url = "/api/v1/scheduled-follow-ups/{$id}";

    // The teacher becomes a stage responsible: may add participants, not generals.
    Sanctum::actingAs($psico);
    $this->postJson("{$url}/stages", ['description' => 'x', 'responsible_id' => $teacher->id, 'due_date' => now()->addDay()->toDateString()])->assertOk();

    Sanctum::actingAs($teacher);
    $this->postJson("{$url}/general-responsibles", ['user_id' => $teacher->id])->assertForbidden();
    $this->postJson("{$url}/general-responsibles/{$psico->id}/remove")->assertForbidden();
    $this->postJson("{$url}/participants", ['user_id' => $director->id])->assertOk();

    Sanctum::actingAs($psico);
    // The last general responsible cannot be removed.
    $this->postJson("{$url}/general-responsibles/{$psico->id}/remove")->assertForbidden();
    $this->postJson("{$url}/general-responsibles", ['user_id' => $director->id])->assertOk()
        ->assertJsonCount(2, 'data.general_responsibles');
    expect($director->unreadNotifications()->first()->data['assigned_as'])->toBe('general_responsible');
    $this->postJson("{$url}/general-responsibles/{$psico->id}/remove")->assertOk()
        ->assertJsonPath('data.general_responsibles.0.id', $director->id)
        ->assertJsonPath('data.assigned_to_id', $director->id);
});

it('only offers people who can see the student as responsibles or participants', function () {
    ['school' => $school, 'psico' => $psico, 'followUpId' => $id] = followUpScenario();
    $stranger = User::factory()->forSchool($school)->teacher()->create();
    $outsider = User::factory()->forSchool(School::factory()->create())->director()->create();
    Sanctum::actingAs($psico);
    $url = "/api/v1/scheduled-follow-ups/{$id}";

    foreach ([$stranger, $outsider] as $person) {
        $this->postJson("{$url}/stages", ['description' => 'x', 'responsible_id' => $person->id, 'due_date' => now()->toDateString()])
            ->assertJsonValidationErrors('responsible_id');
        $this->postJson("{$url}/participants", ['user_id' => $person->id])->assertJsonValidationErrors('user_id');
        $this->postJson("{$url}/general-responsibles", ['user_id' => $person->id])->assertJsonValidationErrors('user_id');
    }
});

it('requires a reason and no pending stages to withdraw, and a successor if you are the only general responsible', function () {
    ['psico' => $psico, 'director' => $director, 'teacher' => $teacher, 'followUpId' => $id] = followUpScenario();
    $url = "/api/v1/scheduled-follow-ups/{$id}";

    Sanctum::actingAs($director);
    $this->postJson("{$url}/withdraw", [])->assertJsonValidationErrors('reason');

    // Psico has the (pending) first stage.
    Sanctum::actingAs($psico);
    $this->postJson("{$url}/withdraw", ['reason' => 'Me voy'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Close or reassign your pending stages before withdrawing.');
    $this->patchJson('/api/v1/follow-up-stages/'.firstStageId($id), ['responsible_id' => $teacher->id])->assertOk();
    $this->postJson("{$url}/withdraw", ['reason' => 'Me voy'])->assertJsonValidationErrors('new_general_responsible_id');
    $this->postJson("{$url}/withdraw", ['reason' => 'Me voy', 'new_general_responsible_id' => $psico->id])
        ->assertJsonValidationErrors('new_general_responsible_id');

    $this->postJson("{$url}/withdraw", ['reason' => 'Cambio de equipo', 'new_general_responsible_id' => $director->id])
        ->assertOk()
        ->assertJsonPath('data.viewer.active', false)
        ->assertJsonPath('data.can.hide', true);

    $followUp = ScheduledFollowUp::with('members', 'events')->findOrFail($id);
    expect($followUp->assigned_to_id)->toBe($director->id)
        ->and($followUp->memberFor($psico)->withdrawal_reason)->toBe('Cambio de equipo')
        ->and($followUp->events->firstWhere('type.value', 'withdrew')->note)->toBe('Cambio de equipo');

    // The new general responsible carries on; the person who left sees it
    // only up to the moment they withdrew.
    $this->travel(1)->minutes();
    Sanctum::actingAs($director);
    $this->postJson("{$url}/stages", ['description' => 'Etapa nueva', 'responsible_id' => $director->id, 'due_date' => now()->addDays(3)->toDateString()])->assertOk();

    Sanctum::actingAs($psico);
    $view = $this->getJson($url)->assertOk();
    expect(collect($view->json('data.stages'))->pluck('description')->all())->not->toContain('Etapa nueva')
        ->and(collect($view->json('data.events'))->pluck('type')->last())->toBe('withdrew');
    $this->postJson("{$url}/stages", ['description' => 'x', 'responsible_id' => $psico->id, 'due_date' => now()->toDateString()])->assertForbidden();

    // Still in their inbox until they hide it.
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("{$url}/hide")->assertOk();
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(0, 'data');
    // Hiding is only for people who left.
    Sanctum::actingAs($director);
    $this->postJson("{$url}/hide")->assertForbidden();
});

it('lets a responsible remove a plain participant with a reason, recorded in the history', function () {
    ['psico' => $psico, 'director' => $director, 'teacher' => $teacher, 'followUpId' => $id] = followUpScenario();
    $url = "/api/v1/scheduled-follow-ups/{$id}";
    Sanctum::actingAs($psico);
    $this->postJson("{$url}/participants", ['user_id' => $teacher->id])->assertOk();

    $this->postJson("{$url}/participants/{$teacher->id}/remove", ['reason' => 'Ya no está con el grupo'])->assertOk();
    // A general responsible is not removed this way.
    $this->postJson("{$url}/participants/{$psico->id}/remove")->assertForbidden();

    $followUp = ScheduledFollowUp::with('members', 'events')->findOrFail($id);
    $member = $followUp->memberFor($teacher);
    expect($member->removed_by_id)->toBe($psico->id)
        ->and($member->removal_reason)->toBe('Ya no está con el grupo')
        ->and($followUp->events->firstWhere('type.value', 'participant_removed')->subject_user_id)->toBe($teacher->id);

    Sanctum::actingAs($teacher);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(1, 'data');
    Sanctum::actingAs($director);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(1, 'data');
});

it('requires a conclusion to resolve and to close a stage', function () {
    ['psico' => $psico, 'followUpId' => $id] = followUpScenario();
    Sanctum::actingAs($psico);

    $this->postJson('/api/v1/follow-up-stages/'.firstStageId($id).'/close', [])->assertJsonValidationErrors('conclusion');
    $this->postJson('/api/v1/follow-up-stages/'.firstStageId($id).'/close', ['conclusion' => 'Hecho'])->assertOk();
    $this->postJson("/api/v1/scheduled-follow-ups/{$id}/resolve", [])->assertJsonValidationErrors('resolution_note');
});

it('requires seeing the student for every action', function () {
    ['student' => $student, 'psico' => $psico, 'teacher' => $teacher, 'followUpId' => $id] = followUpScenario();
    Sanctum::actingAs($psico);
    $this->postJson("/api/v1/scheduled-follow-ups/{$id}/stages", [
        'description' => 'x', 'responsible_id' => $teacher->id, 'due_date' => now()->toDateString(),
    ])->assertOk();
    $stageId = FollowUpStage::query()->where('responsible_id', $teacher->id)->value('id');

    // The teacher stops teaching the student: their stage is out of reach.
    $student->groups()->first()->teachers()->detach($teacher->id);
    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/scheduled-follow-ups/{$id}")->assertForbidden();
    $this->postJson("/api/v1/follow-up-stages/{$stageId}/notes", ['body' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/follow-up-stages/{$stageId}/close", ['conclusion' => 'x'])->assertForbidden();
});

it('never exposes or mutates follow-up stages and people from another school', function () {
    ['psico' => $psico, 'followUpId' => $id] = followUpScenario();
    $otherDirector = User::factory()->forSchool(School::factory()->create())->director()->create();
    $stageId = firstStageId($id);
    Sanctum::actingAs($otherDirector);

    $this->getJson("/api/v1/scheduled-follow-ups/{$id}")->assertNotFound();
    $this->postJson("/api/v1/scheduled-follow-ups/{$id}/stages", ['description' => 'x', 'responsible_id' => $otherDirector->id, 'due_date' => now()->toDateString()])->assertNotFound();
    $this->patchJson("/api/v1/follow-up-stages/{$stageId}", ['description' => 'x'])->assertNotFound();
    $this->postJson("/api/v1/follow-up-stages/{$stageId}/notes", ['body' => 'x'])->assertNotFound();
    $this->postJson("/api/v1/follow-up-stages/{$stageId}/close", ['conclusion' => 'x'])->assertNotFound();
    $this->postJson("/api/v1/scheduled-follow-ups/{$id}/participants", ['user_id' => $otherDirector->id])->assertNotFound();
    $this->postJson("/api/v1/scheduled-follow-ups/{$id}/general-responsibles/{$psico->id}/remove")->assertNotFound();
    $this->postJson("/api/v1/scheduled-follow-ups/{$id}/withdraw", ['reason' => 'x'])->assertNotFound();

    expect(FollowUpStage::withoutGlobalScopes()->findOrFail($stageId)->description)
        ->toBe('Revisar si el ajuste de lectura sigue haciendo falta');
});

it('keeps stage descriptions, conclusions, notes and the title out of the audit diff', function () {
    ['psico' => $psico, 'followUpId' => $id] = followUpScenario();
    $stageId = firstStageId($id);
    Sanctum::actingAs($psico);
    $this->postJson("/api/v1/follow-up-stages/{$stageId}/notes", ['body' => 'Dato sensible'])->assertOk();
    $this->postJson("/api/v1/follow-up-stages/{$stageId}/close", ['conclusion' => 'Conclusión sensible'])->assertOk();

    $stageLogs = AuditLog::where('auditable_type', FollowUpStage::class)->where('auditable_id', $stageId)->get();
    $created = $stageLogs->firstWhere('action.value', 'created');
    $updated = $stageLogs->firstWhere('action.value', 'updated');
    $noteLog = AuditLog::where('auditable_type', FollowUpStageNote::class)->firstOrFail();
    $followUpLog = AuditLog::where('auditable_type', ScheduledFollowUp::class)->where('auditable_id', $id)->firstOrFail();

    expect($created->changes['description'])->toBe('[excluded]')
        ->and($updated->changes['conclusion'])->toBe(['changed' => true])
        ->and($noteLog->changes['body'])->toBe('[excluded]')
        ->and($followUpLog->changes['title'])->toBe('[excluded]');
});
