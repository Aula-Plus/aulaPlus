<?php

use App\Models\Accommodation;
use App\Models\AuditLog;
use App\Models\Barrier;
use App\Models\Comment;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\School;
use App\Models\Student;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * Student-file change history (ClickUp 86e3qkwmc): every addition,
 * modification and removal across the editable parts of the file is
 * recorded with actor, role, section and values; reads are never recorded;
 * the endpoint is psychopedagogy/direction-only and every entry respects the
 * original record's visibility.
 */

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->director = User::factory()->forSchool($this->school)->director()->create(['name' => 'Dora Directora']);
    $this->psychopedagogue = User::factory()->forSchool($this->school)->psychopedagogue()->create(['name' => 'Paula Psico']);
    $this->student = Student::factory()->create(['school_id' => $this->school->id]);
});

function historyOf(Student $student, array $query = []): TestResponse
{
    return test()->getJson("/api/v1/students/{$student->id}/history?".http_build_query($query));
}

/**
 * @return list<array<string, mixed>>
 */
function entriesOf(Student $student, array $query = []): array
{
    return historyOf($student, $query)->assertOk()->json('data');
}

it('records an accommodation alta, modificación and baja with actor, role, section and values', function () {
    Sanctum::actingAs($this->psychopedagogue);

    $id = $this->postJson("/api/v1/students/{$this->student->id}/accommodations", [
        'category' => 'access',
        'type' => 'Tiempo extra',
        'description' => 'Necesita tiempo extra en escritos',
        'focus_area' => 'mathematics',
    ])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/accommodations/{$id}", [
        'category' => 'access',
        'type' => 'Tiempo extra',
        'description' => 'Tiempo extra y lectura en voz alta',
        'focus_area' => 'mathematics',
    ])->assertOk();
    Accommodation::findOrFail($id)->delete();

    Sanctum::actingAs($this->director);
    $entries = entriesOf($this->student, ['section' => 'accommodations']);

    expect(collect($entries)->pluck('action')->all())->toBe(['deleted', 'updated', 'created']);

    [$deleted, $updated, $created] = $entries;

    expect($created['actor'])->toBe(['id' => $this->psychopedagogue->id, 'name' => 'Paula Psico', 'role' => 'psychopedagogue'])
        ->and($created['section'])->toBe('accommodations')
        ->and($created['element'])->toMatchArray(['type' => 'accommodation', 'id' => $id, 'label' => 'Tiempo extra'])
        ->and(collect($created['changes'])->firstWhere('field', 'description'))
        ->toBe(['field' => 'description', 'before' => null, 'after' => 'Necesita tiempo extra en escritos']);

    expect($updated['changes'])->toBe([
        ['field' => 'description', 'before' => 'Necesita tiempo extra en escritos', 'after' => 'Tiempo extra y lectura en voz alta'],
    ]);

    expect(collect($deleted['changes'])->firstWhere('field', 'type'))
        ->toBe(['field' => 'type', 'before' => 'Tiempo extra', 'after' => null]);
});

it('never exposes redacted fields (internal AI rule, private report path) even to an allowed viewer', function () {
    Sanctum::actingAs($this->psychopedagogue);
    $report = TechnicalReport::factory()->create([
        'student_id' => $this->student->id,
        'school_id' => $this->school->id,
        'summary' => 'Informe inicial',
        'document_url' => 'reports/secret-path.pdf',
    ]);
    $report->update(['summary' => 'Informe revisado', 'document_url' => 'reports/other-path.pdf']);

    Sanctum::actingAs($this->director);
    $response = historyOf($this->student, ['section' => 'reports'])->assertOk();

    expect($response->getContent())->not->toContain('secret-path')->not->toContain('other-path');

    $updated = $response->json('data.0');
    expect($updated['action'])->toBe('updated')
        ->and($updated['changes'])->toContain(['field' => 'summary', 'before' => 'Informe inicial', 'after' => 'Informe revisado'])
        ->and($updated['changes'])->toContain(['field' => 'document_url', 'redacted' => true]);
});

it('splits a student-form save into one entry per section, including class changes', function () {
    $groupA = Group::factory()->create(['school_id' => $this->school->id, 'name' => '3°A']);
    $groupB = Group::factory()->create(['school_id' => $this->school->id, 'name' => '3°B']);
    $this->student->groups()->attach($groupA->id, ['school_year' => 2026]);
    Sanctum::actingAs($this->director);

    $this->putJson("/api/students/{$this->student->id}", [
        'full_name' => 'Nombre Nuevo',
        'tracking_notes' => 'Notas nuevas',
        'related_documents' => ['acta.pdf'],
        'group_id' => $groupB->id,
        'school_year' => 2026,
    ])->assertOk();

    $bySection = collect(entriesOf($this->student))->keyBy('section');

    expect($bySection->keys()->sort()->values()->all())->toBe(['documents', 'learning_profile', 'student_data'])
        ->and($bySection['learning_profile']['changes'])->toBe([['field' => 'tracking_notes', 'before' => null, 'after' => 'Notas nuevas']])
        ->and($bySection['documents']['changes'])->toBe([['field' => 'related_documents', 'before' => null, 'after' => ['acta.pdf']]]);

    $studentData = collect(entriesOf($this->student, ['section' => 'student_data']))->pluck('changes')->flatten(1);
    expect($studentData)->toContain(['field' => 'group', 'before' => '3°A', 'after' => '3°B'])
        ->and($studentData->firstWhere('field', 'full_name')['after'])->toBe('Nombre Nuevo');
});

it('records the team summary, observations, barriers, barrier links and follow-ups', function () {
    $teacher = User::factory()->forSchool($this->school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $this->school->id]);
    leadGroup($group, $teacher);
    $this->student->groups()->attach($group->id, ['school_year' => now()->year]);

    Sanctum::actingAs($this->psychopedagogue);
    $summary = ['strengths' => 'Lee bien', 'difficulties' => 'Cálculo', 'adjustments' => 'Más tiempo'];
    $this->putJson("/api/v1/students/{$this->student->id}/team-summary", $summary)->assertOk();
    $this->putJson("/api/v1/students/{$this->student->id}/team-summary", [...$summary, 'strengths' => 'Lee muy bien'])->assertOk();

    $this->postJson("/api/v1/students/{$this->student->id}/comments", ['content' => 'Participa más en clase'])->assertCreated();

    $barrier = Barrier::factory()->create([
        'student_id' => $this->student->id, 'school_id' => $this->school->id,
        'description' => 'Dificultad de atención', 'coping_strategy' => null,
    ]);
    $barrier->update(['coping_strategy' => 'Pausas activas']);
    $accommodation = Accommodation::factory()->create(['student_id' => $this->student->id, 'school_id' => $this->school->id, 'type' => 'Pausas']);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations", ['accommodation_id' => $accommodation->id])->assertCreated();

    Sanctum::actingAs($this->director);
    $this->postJson("/api/v1/barriers/{$barrier->id}/accommodations/{$accommodation->id}/validate")->assertOk();

    Sanctum::actingAs($teacher);
    $followUpId = $this->postJson("/api/v1/students/{$this->student->id}/scheduled-follow-ups", [
        'description' => 'Revisar lectura', 'due_date' => now()->addWeek()->toDateString(),
    ])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/scheduled-follow-ups/{$followUpId}/resolve", ['resolution_note' => 'Mejoró'])->assertOk();

    Sanctum::actingAs($this->director);

    $summaryUpdate = collect(entriesOf($this->student, ['section' => 'summary']))->firstWhere('action', 'updated');
    expect(collect($summaryUpdate['changes'])->firstWhere('field', 'team_summary_strengths'))
        ->toBe(['field' => 'team_summary_strengths', 'before' => 'Lee bien', 'after' => 'Lee muy bien']);

    $observation = entriesOf($this->student, ['section' => 'observations'])[0];
    expect($observation['action'])->toBe('created')
        ->and($observation['element']['label'])->toBe('Participa más en clase');

    $barriers = collect(entriesOf($this->student, ['section' => 'barriers']));
    expect($barriers->pluck('element.type')->unique()->sort()->values()->all())->toBe(['barrier', 'barrier_accommodation'])
        ->and($barriers->firstWhere('element.type', 'barrier_accommodation')['actor']['role'])->toBe('director')
        ->and($barriers->firstWhere('element.type', 'barrier_accommodation')['changes'])
        ->toBe([['field' => 'validated', 'before' => false, 'after' => true]])
        ->and($barriers->where('element.type', 'barrier')->firstWhere('action', 'updated')['changes'])
        ->toBe([['field' => 'coping_strategy', 'before' => null, 'after' => 'Pausas activas']]);

    $followUps = collect(entriesOf($this->student, ['section' => 'follow_ups']));
    expect($followUps->pluck('action')->all())->toBe(['updated', 'created'])
        ->and($followUps[0]['actor']['role'])->toBe('teacher')
        ->and($followUps[0]['changes'])->toContain(['field' => 'resolution_note', 'before' => null, 'after' => 'Mejoró']);

    expect(ScheduledFollowUp::count())->toBe(1);
});

it('does not record anything when the file is only opened, browsed or consulted', function () {
    Comment::factory()->create([
        'school_id' => $this->school->id, 'commentable_id' => $this->student->id, 'author_id' => $this->psychopedagogue->id,
    ]);
    Accommodation::factory()->create(['student_id' => $this->student->id, 'school_id' => $this->school->id]);
    $before = AuditLog::count();

    foreach ([$this->director, $this->psychopedagogue] as $viewer) {
        Sanctum::actingAs($viewer);
        $this->getJson("/api/students/{$this->student->id}")->assertOk();
        $this->getJson("/api/v1/students/{$this->student->id}/tracking")->assertOk();
        $this->getJson("/api/v1/students/{$this->student->id}/comments")->assertOk();
        $this->getJson("/api/v1/students/{$this->student->id}/scheduled-follow-ups")->assertOk();
        $this->getJson("/api/v1/students/{$this->student->id}/performance-timeline")->assertOk();
        $this->getJson("/api/v1/students/{$this->student->id}/results")->assertOk();
        historyOf($this->student)->assertOk();
    }

    expect(AuditLog::count())->toBe($before);
});

it('filters by person, section, action and date range, and lists the people for the filter', function () {
    AuditLog::query()->delete();
    Sanctum::actingAs($this->psychopedagogue);
    Carbon::setTestNow('2026-10-01 12:00:00');
    $barrier = Barrier::factory()->create(['student_id' => $this->student->id, 'school_id' => $this->school->id]);

    Sanctum::actingAs($this->director);
    Carbon::setTestNow('2026-10-10 12:00:00');
    $barrier->update(['active' => ! $barrier->active]);
    Accommodation::factory()->create(['student_id' => $this->student->id, 'school_id' => $this->school->id]);
    Carbon::setTestNow();

    expect(collect(entriesOf($this->student, ['user_id' => $this->psychopedagogue->id]))->pluck('actor.id')->unique()->all())
        ->toBe([$this->psychopedagogue->id]);

    expect(collect(entriesOf($this->student, ['section' => 'barriers']))->pluck('section')->unique()->all())->toBe(['barriers'])
        ->and(collect(entriesOf($this->student, ['action' => 'updated']))->pluck('action')->unique()->all())->toBe(['updated']);

    $early = entriesOf($this->student, ['from' => '2026-09-30', 'to' => '2026-10-02']);
    expect($early)->toHaveCount(1)
        ->and($early[0]['actor']['id'])->toBe($this->psychopedagogue->id);

    // ISO instants (what the SPA sends for the viewer's local day) work too.
    expect(entriesOf($this->student, ['from' => '2026-10-10T00:00:00-03:00']))->toHaveCount(2);

    $actors = historyOf($this->student, ['user_id' => $this->director->id])->json('actors');
    expect($actors)->toBe([
        ['id' => $this->director->id, 'name' => 'Dora Directora', 'role' => 'director'],
        ['id' => $this->psychopedagogue->id, 'name' => 'Paula Psico', 'role' => 'psychopedagogue'],
    ]);
});

it('validates the filters', function () {
    Sanctum::actingAs($this->director);

    historyOf($this->student, ['section' => 'grades'])->assertUnprocessable()->assertJsonValidationErrors('section');
    historyOf($this->student, ['action' => 'viewed'])->assertUnprocessable()->assertJsonValidationErrors('action');
    historyOf($this->student, ['from' => '2026-10-10', 'to' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
});

it('forbids teachers, even for a student they teach', function () {
    $teacher = User::factory()->forSchool($this->school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $this->school->id]);
    leadGroup($group, $teacher);
    $this->student->groups()->attach($group->id, ['school_year' => now()->year]);
    Sanctum::actingAs($teacher);

    historyOf($this->student)->assertForbidden();
});

it('isolates tenants: another school cannot reach the history nor see its entries', function () {
    $otherSchool = School::factory()->create();
    $otherDirector = User::factory()->forSchool($otherSchool)->director()->create();
    $otherStudent = Student::factory()->create(['school_id' => $otherSchool->id]);

    Sanctum::actingAs($this->psychopedagogue);
    Barrier::factory()->create(['student_id' => $this->student->id, 'school_id' => $this->school->id]);

    Sanctum::actingAs($otherDirector);

    historyOf($this->student)->assertNotFound();
    // Only the other student's own (system) creation, nothing of this school.
    expect(collect(entriesOf($otherStudent))->pluck('element.type')->all())->toBe(['student']);
});

it('does not expose a psychopedagogy-only observation to the director through the history', function () {
    Sanctum::actingAs($this->psychopedagogue);
    $this->postJson("/api/v1/students/{$this->student->id}/comments", [
        'content' => 'Reserved note', 'visible_to' => ['psychopedagogue'],
    ])->assertCreated();
    $this->postJson("/api/v1/students/{$this->student->id}/comments", ['content' => 'Note for everyone'])->assertCreated();

    $own = historyOf($this->student, ['section' => 'observations'])->assertOk();
    expect($own->json('data'))->toHaveCount(2)
        ->and($own->getContent())->toContain('Reserved note');

    Sanctum::actingAs($this->director);
    $director = historyOf($this->student, ['section' => 'observations'])->assertOk();

    expect($director->json('data'))->toHaveCount(1)
        ->and($director->json('meta.total'))->toBe(1)
        ->and($director->getContent())->not->toContain('Reserved note')
        ->and($director->json('data.0.element.label'))->toBe('Note for everyone');
});

it('keeps an author-only observation out of every other viewer’s history', function () {
    $otherPsychopedagogue = User::factory()->forSchool($this->school)->psychopedagogue()->create();
    Sanctum::actingAs($this->psychopedagogue);
    $this->postJson("/api/v1/students/{$this->student->id}/comments", [
        'content' => 'Nota personal', 'author_only' => true,
    ])->assertCreated();

    expect(entriesOf($this->student, ['section' => 'observations']))->toHaveCount(1);

    Sanctum::actingAs($otherPsychopedagogue);
    expect(entriesOf($this->student, ['section' => 'observations']))->toBe([]);
});

it('keeps hiding a restricted observation after it was deleted', function () {
    Sanctum::actingAs($this->psychopedagogue);
    $comment = Comment::factory()->create([
        'school_id' => $this->school->id, 'commentable_id' => $this->student->id,
        'author_id' => $this->psychopedagogue->id, 'visible_to' => ['psychopedagogue'],
    ]);
    $comment->delete();

    expect(collect(entriesOf($this->student, ['section' => 'observations']))->pluck('action')->all())->toBe(['deleted', 'created']);

    Sanctum::actingAs($this->director);
    expect(entriesOf($this->student, ['section' => 'observations']))->toBe([]);
});

it('stores the readable values encrypted at rest and keeps the plain diff redacted', function () {
    Sanctum::actingAs($this->psychopedagogue);
    $barrier = Barrier::factory()->create([
        'student_id' => $this->student->id, 'school_id' => $this->school->id, 'description' => 'Contenido sensible del alumno',
    ]);

    $raw = DB::table('audit_logs')->where('auditable_type', Barrier::class)->where('auditable_id', $barrier->id)->first();

    expect($raw->details)->not->toContain('Contenido sensible')
        ->and($raw->changes)->not->toContain('Contenido sensible')
        ->and($raw->student_id)->toBe($this->student->id)
        ->and($raw->section)->toBe('barriers')
        ->and($raw->actor_role)->toBe('psychopedagogue');
});

it('still shows entries written before the history existed, without their redacted content', function () {
    $accommodation = Accommodation::factory()->create(['student_id' => $this->student->id, 'school_id' => $this->school->id]);
    AuditLog::query()->delete();
    AuditLog::create([
        'school_id' => $this->school->id,
        'auditable_type' => Accommodation::class,
        'auditable_id' => $accommodation->id,
        'student_id' => $this->student->id,
        'section' => 'accommodations',
        'action' => 'updated',
        'user_id' => $this->director->id,
        'origin' => 'user',
        'changes' => ['approved' => ['before' => null, 'after' => true], 'description' => ['changed' => true]],
        'created_at' => now(),
    ]);
    Sanctum::actingAs($this->director);

    $entry = entriesOf($this->student)[0];

    expect($entry['actor']['role'])->toBeNull()
        ->and($entry['changes'])->toBe([
            ['field' => 'description', 'redacted' => true],
            ['field' => 'approved', 'before' => null, 'after' => true],
        ]);
});
