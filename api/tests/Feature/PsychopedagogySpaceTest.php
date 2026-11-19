<?php

use App\Models\Comment;
use App\Models\Group;
use App\Models\MeetingSheet;
use App\Models\MeetingSheetType;
use App\Models\PsychopedagogyMeeting;
use App\Models\School;
use App\Models\Student;
use App\Models\TherapeuticCompanion;
use App\Models\User;
use App\Notifications\MeetingActionAssignedNotification;
use App\Notifications\MeetingSheetFragmentNotification;
use App\Support\SchoolTime;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * «Espacio de psicopedagogía» (screen 10, ClickUp 1dfd3mc4bge): agenda,
 * therapeutic companions and meeting sheets. No teacher enters; direction
 * sees the administrative part only; nothing reaches a file before
 * psychopedagogy confirms the split.
 */
function spaceScenario(): array
{
    $school = School::factory()->create();
    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Laura Ferrando']);
    $director = User::factory()->forSchool($school)->director()->create(['name' => 'Adriana Ruiz']);
    $mariana = User::factory()->forSchool($school)->teacher()->create(['name' => 'Mariana López']);
    $nico = User::factory()->forSchool($school)->teacher()->create(['name' => 'Pablo Gómez']);
    $year = SchoolTime::today()->year;
    $groupA = Group::factory()->create(['school_id' => $school->id, 'name' => '2°EMS-A', 'school_year' => $year]);
    $groupB = Group::factory()->create(['school_id' => $school->id, 'name' => '3°EMS-B', 'school_year' => $year]);
    $lucia = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Lucía Pereyra']);
    $nicolas = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Nicolás Sosa']);
    $lucia->groups()->attach($groupA, ['school_year' => $year]);
    $nicolas->groups()->attach($groupB, ['school_year' => $year]);
    leadGroup($groupA, $mariana);
    leadGroup($groupB, $nico);
    $types = MeetingSheetType::withoutGlobalScopes()->where('school_id', $school->id)->orderBy('position')->get();

    return compact('school', 'psycho', 'director', 'mariana', 'nico', 'groupA', 'groupB', 'lucia', 'nicolas', 'types');
}

it('starts every school with the three sheet types', function () {
    ['types' => $types] = spaceScenario();

    expect($types->pluck('name')->all())->toBe(['Reunión de profesores', 'Entrevista con familia', 'Reunión con equipo tratante']);
});

it('keeps every teacher out of the whole space', function () {
    ['mariana' => $teacher, 'lucia' => $lucia, 'types' => $types] = spaceScenario();
    Sanctum::actingAs($teacher);

    $this->getJson('/api/v1/psychopedagogy/overview')->assertForbidden();
    $this->getJson('/api/v1/psychopedagogy/meetings')->assertForbidden();
    $this->getJson('/api/v1/psychopedagogy/staff')->assertForbidden();
    $this->postJson('/api/v1/psychopedagogy/meetings', [
        'kind' => 'individual_session', 'scheduled_on' => '2026-11-16', 'start_time' => '10:00', 'student_id' => $lucia->id,
    ])->assertForbidden();
    $this->getJson('/api/v1/psychopedagogy/companions')->assertForbidden();
    $this->postJson('/api/v1/psychopedagogy/companions', [
        'student_id' => $lucia->id, 'name' => 'X', 'weekly_hours' => 2, 'days' => ['monday'],
    ])->assertForbidden();
    $this->getJson('/api/v1/psychopedagogy/sheets')->assertForbidden();
    $this->postJson('/api/v1/psychopedagogy/sheets', [
        'meeting_sheet_type_id' => $types[0]->id, 'held_on' => '2026-11-18', 'student_ids' => [$lucia->id], 'content' => 'x',
    ])->assertForbidden();
    $this->getJson('/api/v1/meeting-sheet-types')->assertForbidden();
});

it('lets psychopedagogy load a companion and direction see it without the reason', function () {
    ['psycho' => $psycho, 'director' => $director, 'lucia' => $lucia] = spaceScenario();

    Sanctum::actingAs($psycho);
    $id = $this->postJson('/api/v1/psychopedagogy/companions', [
        'student_id' => $lucia->id, 'name' => 'Romina Silva', 'weekly_hours' => 6,
        'days' => ['tuesday', 'thursday'], 'reason' => 'Apoyo en instancias de evaluación',
    ])->assertCreated()->assertJsonPath('data.reason', 'Apoyo en instancias de evaluación')->json('data.id');
    expect($lucia->fresh()->has_therapeutic_companion)->toBeTrue();

    $this->getJson('/api/v1/psychopedagogy/companions')->assertOk()
        ->assertJsonPath('data.0.reason', 'Apoyo en instancias de evaluación');
    $this->patchJson("/api/v1/psychopedagogy/companions/{$id}", ['weekly_hours' => 4])->assertOk()
        ->assertJsonPath('data.weekly_hours', 4);

    Sanctum::actingAs($director);
    $row = $this->getJson('/api/v1/psychopedagogy/companions')->assertOk()->json('data.0');
    expect($row['name'])->toBe('Romina Silva')
        ->and($row['student']['name'])->toBe('Lucía Pereyra')
        ->and($row['group']['name'])->toBe('2°EMS-A')
        ->and($row['weekly_hours'])->toEqual(4)
        ->and($row['days'])->toBe(['tuesday', 'thursday'])
        ->and($row)->not->toHaveKey('reason');
    $this->getJson('/api/v1/psychopedagogy/overview')->assertOk()->assertJsonPath('data.active_companions', 1);
    // Direction reads, never edits.
    $this->patchJson("/api/v1/psychopedagogy/companions/{$id}", ['weekly_hours' => 2])->assertForbidden();
    $this->deleteJson("/api/v1/psychopedagogy/companions/{$id}")->assertForbidden();

    Sanctum::actingAs($psycho);
    $this->deleteJson("/api/v1/psychopedagogy/companions/{$id}")->assertNoContent();
    expect($lucia->fresh()->has_therapeutic_companion)->toBeFalse();
});

it('shows the companion in the student file to its teacher, without the reason', function () {
    ['school' => $school, 'psycho' => $psycho, 'mariana' => $teacher, 'lucia' => $lucia] = spaceScenario();
    Tenancy::forSchool($school, fn () => TherapeuticCompanion::create([
        'student_id' => $lucia->id, 'name' => 'Romina Silva', 'weekly_hours' => 6, 'days' => ['tuesday'], 'reason' => 'Motivo reservado',
    ]));

    Sanctum::actingAs($teacher);
    $companion = $this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertOk()->json('data.therapeutic_companions.0');
    expect($companion['name'])->toBe('Romina Silva')
        ->and($companion['days'])->toBe(['tuesday'])
        ->and($companion)->not->toHaveKey('reason');

    Sanctum::actingAs($psycho);
    $this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertOk()
        ->assertJsonPath('data.therapeutic_companions.0.reason', 'Motivo reservado');
});

it('validates the companion record', function () {
    ['psycho' => $psycho, 'lucia' => $lucia] = spaceScenario();
    Sanctum::actingAs($psycho);

    $this->postJson('/api/v1/psychopedagogy/companions', [
        'student_id' => $lucia->id, 'name' => '', 'weekly_hours' => 0, 'days' => ['funday'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'weekly_hours', 'days.0']);
    $this->postJson('/api/v1/psychopedagogy/companions', [
        'student_id' => Student::factory()->create()->id, 'name' => 'X', 'weekly_hours' => 2, 'days' => ['monday'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
});

it('schedules a session, marks it done and leaves the note in the file, «solo Psicopedagogía» by default', function () {
    ['psycho' => $psycho, 'director' => $director, 'mariana' => $teacher, 'lucia' => $lucia, 'groupA' => $groupA, 'types' => $types] = spaceScenario();
    Sanctum::actingAs($psycho);

    $session = $this->postJson('/api/v1/psychopedagogy/meetings', [
        'kind' => 'individual_session', 'scheduled_on' => '2026-11-16', 'start_time' => '10:00', 'student_id' => $lucia->id,
        'attendee_ids' => [$psycho->id],
    ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.student.name', 'Lucía Pereyra')->json('data.id');
    $this->postJson('/api/v1/psychopedagogy/meetings', [
        'kind' => 'meeting', 'meeting_sheet_type_id' => $types[0]->id, 'scheduled_on' => '2026-11-18', 'start_time' => '14:00',
        'group_id' => $groupA->id, 'attendee_ids' => [$psycho->id, $teacher->id],
    ])->assertCreated()->assertJsonPath('data.sheet_type.name', 'Reunión de profesores');
    $this->postJson('/api/v1/psychopedagogy/meetings', [
        'kind' => 'meeting', 'scheduled_on' => '2026-11-18', 'start_time' => '14:00',
    ])->assertUnprocessable()->assertJsonValidationErrors(['meeting_sheet_type_id']);

    $this->postJson("/api/v1/psychopedagogy/meetings/{$session}/complete", ['note' => 'Trabajamos la lectura de consignas.'])
        ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.has_note', true);
    $this->postJson("/api/v1/psychopedagogy/meetings/{$session}/complete")->assertUnprocessable();

    $note = Comment::withoutGlobalScopes()->where('commentable_id', $lucia->id)->sole();
    expect($note->content)->toBe('Trabajamos la lectura de consignas.')
        ->and($note->visible_to)->toBe(['psychopedagogue'])
        ->and($note->isVisibleTo($teacher))->toBeFalse()
        ->and($note->isVisibleTo($director))->toBeFalse();

    $this->getJson('/api/v1/psychopedagogy/meetings?week=2026-11-18')->assertOk()->assertJsonCount(2, 'data');

    // Direction: how many of each type, never with whom.
    Sanctum::actingAs($director);
    $overview = $this->getJson('/api/v1/psychopedagogy/overview?week=2026-11-18')->assertOk()->json('data');
    expect($overview['meetings_this_week'])->toBe(2)
        ->and(collect($overview['workload'])->firstWhere('key', 'individual_session')['count'])->toBe(1)
        ->and(collect($overview['workload'])->firstWhere('name', 'Reunión de profesores')['count'])->toBe(1)
        ->and(json_encode($overview))->not->toContain('Lucía');
    $this->getJson('/api/v1/psychopedagogy/meetings')->assertForbidden();
    $this->postJson("/api/v1/psychopedagogy/meetings/{$session}/complete")->assertForbidden();
});

it('splits a teachers meeting about two students, lands each fragment in its file with its scope and notifies accordingly', function () {
    Notification::fake();
    ['psycho' => $psycho, 'director' => $director, 'mariana' => $mariana, 'nico' => $nico, 'groupA' => $groupA,
        'lucia' => $lucia, 'nicolas' => $nicolas, 'types' => $types] = spaceScenario();
    Sanctum::actingAs($psycho);

    $sheetId = $this->postJson('/api/v1/psychopedagogy/sheets', [
        'meeting_sheet_type_id' => $types[0]->id, 'held_on' => '2026-11-18', 'group_id' => $groupA->id,
        'attendee_ids' => [$psycho->id, $mariana->id],
        'student_ids' => [$lucia->id, $nicolas->id],
        'content' => 'Se repasó el tiempo extendido de Lucía y el objetivo ampliado de Nicolás.',
        'fragments' => [
            ['student_id' => $lucia->id, 'content' => 'El tiempo extendido viene funcionando.', 'scope' => 'everyone'],
            ['student_id' => $nicolas->id, 'content' => 'Objetivo ampliado en sistemas de ecuaciones.', 'scope' => 'psychopedagogue'],
        ],
        'actions' => [
            ['description' => 'Confirmar con Lucía si el tiempo extendido alcanza', 'owner_id' => $mariana->id, 'due_on' => '2026-11-27'],
            ['description' => 'Ver con Nicolás otro desafío', 'owner_id' => $psycho->id, 'due_on' => '2026-12-04'],
        ],
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonCount(2, 'data.fragments')->json('data.id');

    // Nothing reaches a file nor anyone before the confirmation.
    expect(Comment::withoutGlobalScopes()->count())->toBe(0);
    Notification::assertNothingSent();
    Sanctum::actingAs($mariana);
    $this->getJson('/api/v1/meeting-actions/mine')->assertOk()->assertJsonCount(0, 'data');

    // «Editar antes»: the draft can be corrected.
    Sanctum::actingAs($psycho);
    $sheet = $this->getJson("/api/v1/psychopedagogy/sheets/{$sheetId}")->assertOk()->json('data');
    $payload = [
        'meeting_sheet_type_id' => $types[0]->id, 'held_on' => '2026-11-18', 'group_id' => $groupA->id,
        'attendee_ids' => [$psycho->id, $mariana->id], 'student_ids' => [$lucia->id, $nicolas->id],
        'content' => $sheet['content'],
        'fragments' => [
            ['student_id' => $lucia->id, 'content' => 'El tiempo extendido de Lucía viene funcionando.', 'scope' => 'everyone'],
            ['student_id' => $nicolas->id, 'content' => 'Objetivo ampliado en sistemas de ecuaciones.', 'scope' => 'psychopedagogue'],
        ],
        'actions' => collect($sheet['actions'])->map(fn ($a) => ['description' => $a['description'], 'owner_id' => $a['owner']['id'], 'due_on' => $a['due_on']])->all(),
    ];
    $this->putJson("/api/v1/psychopedagogy/sheets/{$sheetId}", $payload)->assertOk();

    $this->postJson("/api/v1/psychopedagogy/sheets/{$sheetId}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->postJson("/api/v1/psychopedagogy/sheets/{$sheetId}/confirm")->assertUnprocessable();
    $this->putJson("/api/v1/psychopedagogy/sheets/{$sheetId}", $payload)->assertUnprocessable();

    $luciaNote = Comment::withoutGlobalScopes()->where('commentable_id', $lucia->id)->sole();
    $nicolasNote = Comment::withoutGlobalScopes()->where('commentable_id', $nicolas->id)->sole();
    expect($luciaNote->content)->toBe('Reunión de profesores · 18/11/2026 — El tiempo extendido de Lucía viene funcionando.')
        ->and($luciaNote->visible_to)->toBeNull()
        ->and($luciaNote->content)->not->toContain('Nicolás')
        ->and($nicolasNote->visible_to)->toBe(['psychopedagogue']);

    // Lucía's teacher (also present) is told; Nicolás' fragment is «solo
    // Psicopedagogía», so his teacher is not; direction was not there.
    Notification::assertSentTo($mariana, MeetingSheetFragmentNotification::class,
        fn ($n) => $n->toArray($mariana)['student_id'] === $lucia->id);
    Notification::assertNotSentTo($nico, MeetingSheetFragmentNotification::class);
    Notification::assertNotSentTo($director, MeetingSheetFragmentNotification::class);
    Notification::assertNotSentTo($psycho, MeetingSheetFragmentNotification::class);
    Notification::assertSentTo($mariana, MeetingActionAssignedNotification::class);
    Notification::assertNotSentTo($psycho, MeetingActionAssignedNotification::class);

    // The teacher sees only her student's fragment, in the file.
    Sanctum::actingAs($mariana);
    $this->getJson("/api/v1/students/{$lucia->id}/comments")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $luciaNote->id);
    $this->getJson("/api/v1/students/{$nicolas->id}/comments")->assertForbidden();
    $this->getJson("/api/v1/psychopedagogy/sheets/{$sheetId}")->assertForbidden();
    $actions = $this->getJson('/api/v1/meeting-actions/mine')->assertOk()->assertJsonCount(1, 'data')->json('data');
    expect($actions[0]['description'])->toBe('Confirmar con Lucía si el tiempo extendido alcanza')
        ->and($actions[0]['meeting']['sheet_type_name'])->toBe('Reunión de profesores');
    $this->postJson("/api/v1/meeting-actions/{$actions[0]['id']}/complete")->assertOk()->assertJsonPath('data.done', true);
    $this->getJson('/api/v1/meeting-actions/mine')->assertOk()->assertJsonCount(0, 'data');

    // Nicolás' teacher: nothing of the sheet, no note visible.
    Sanctum::actingAs($nico);
    $this->getJson("/api/v1/students/{$nicolas->id}/comments")->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/meeting-actions/{$actions[0]['id']}/complete")->assertForbidden();

    // Direction: that it existed, its date, type and group — nothing else.
    Sanctum::actingAs($director);
    $row = $this->getJson('/api/v1/psychopedagogy/sheets')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    expect(array_keys($row))->toEqualCanonicalizing(['id', 'held_on', 'sheet_type', 'group'])
        ->and($row['group']['name'])->toBe('2°EMS-A')
        ->and(json_encode($row))->not->toContain('Lucía');
    $this->getJson("/api/v1/psychopedagogy/sheets/{$sheetId}")->assertForbidden();
    $this->postJson("/api/v1/psychopedagogy/sheets/{$sheetId}/confirm")->assertForbidden();
    expect($nicolasNote->isVisibleTo($director))->toBeFalse();
});

it('rejects a fragment for a student who was not discussed, and confirming without fragments', function () {
    ['psycho' => $psycho, 'lucia' => $lucia, 'nicolas' => $nicolas, 'types' => $types] = spaceScenario();
    Sanctum::actingAs($psycho);
    $base = ['meeting_sheet_type_id' => $types[1]->id, 'held_on' => '2026-11-17', 'student_ids' => [$lucia->id], 'content' => 'Entrevista.'];

    $this->postJson('/api/v1/psychopedagogy/sheets', [...$base, 'fragments' => [
        ['student_id' => $nicolas->id, 'content' => 'x', 'scope' => 'everyone'],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['fragments.0.student_id']);
    $this->postJson('/api/v1/psychopedagogy/sheets', [...$base, 'fragments' => [
        ['student_id' => $lucia->id, 'content' => 'x', 'scope' => 'anyone'],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['fragments.0.scope']);

    $id = $this->postJson('/api/v1/psychopedagogy/sheets', $base)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/psychopedagogy/sheets/{$id}/confirm")->assertUnprocessable();
});

it('isolates schools', function () {
    ['school' => $school, 'psycho' => $psycho, 'lucia' => $lucia, 'types' => $types] = spaceScenario();
    $other = spaceScenario();

    [$companion, $sheet, $meeting] = Tenancy::forSchool($school, fn () => [
        TherapeuticCompanion::create(['student_id' => $lucia->id, 'name' => 'Romina Silva', 'weekly_hours' => 6, 'days' => ['monday'], 'reason' => 'x']),
        MeetingSheet::create(['meeting_sheet_type_id' => $types[0]->id, 'held_on' => '2026-11-18', 'content' => 'x']),
        PsychopedagogyMeeting::create(['kind' => 'individual_session', 'scheduled_on' => '2026-11-16', 'start_time' => '10:00', 'student_id' => $lucia->id]),
    ]);

    Sanctum::actingAs($other['psycho']);
    $this->getJson('/api/v1/psychopedagogy/companions')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/psychopedagogy/sheets')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/psychopedagogy/sheets/{$sheet->id}")->assertNotFound();
    $this->patchJson("/api/v1/psychopedagogy/companions/{$companion->id}", ['name' => 'Y'])->assertNotFound();
    $this->postJson("/api/v1/psychopedagogy/meetings/{$meeting->id}/complete")->assertNotFound();
    $this->postJson('/api/v1/psychopedagogy/companions', [
        'student_id' => $lucia->id, 'name' => 'X', 'weekly_hours' => 2, 'days' => ['monday'],
    ])->assertUnprocessable();
    $this->postJson('/api/v1/psychopedagogy/sheets', [
        'meeting_sheet_type_id' => $types[0]->id, 'held_on' => '2026-11-18', 'student_ids' => [$other['lucia']->id], 'content' => 'x',
    ])->assertUnprocessable()->assertJsonValidationErrors(['meeting_sheet_type_id']);
    $this->getJson('/api/v1/meeting-sheet-types')->assertOk()->assertJsonCount(3, 'data');
    $this->patchJson("/api/v1/meeting-sheet-types/{$types[0]->id}", ['name' => 'Z'])->assertNotFound();
    expect($psycho->school_id)->not->toBe($other['psycho']->school_id);
});

it('lets direction rename and add sheet types, and nobody else', function () {
    ['psycho' => $psycho, 'director' => $director, 'types' => $types] = spaceScenario();

    Sanctum::actingAs($psycho);
    $this->getJson('/api/v1/meeting-sheet-types')->assertOk()->assertJsonCount(3, 'data');
    $this->postJson('/api/v1/meeting-sheet-types', ['name' => 'Ateneo'])->assertForbidden();
    $this->patchJson("/api/v1/meeting-sheet-types/{$types[0]->id}", ['name' => 'Reunión docente'])->assertForbidden();

    Sanctum::actingAs($director);
    $this->patchJson("/api/v1/meeting-sheet-types/{$types[0]->id}", ['name' => 'Reunión docente'])
        ->assertOk()->assertJsonPath('data.name', 'Reunión docente');
    $this->postJson('/api/v1/meeting-sheet-types', ['name' => 'Ateneo clínico'])->assertCreated()->assertJsonPath('data.position', 3);
    $this->postJson('/api/v1/meeting-sheet-types', ['name' => 'Entrevista con familia'])->assertUnprocessable();
    $this->getJson('/api/v1/meeting-sheet-types')->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.name', 'Reunión docente');
});
