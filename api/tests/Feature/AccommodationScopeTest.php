<?php

use App\Models\Accommodation;
use App\Models\Assessment;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;

/**
 * ClickUp 86e3dpbwy follow-up (Eitán): in the student profile's Ajustes tab a
 * teacher sees since when each accommodation is in force and where it applies
 * (subjects / assessment types), but only the general ones and the ones scoped
 * to a subject they teach the student. Never origin, validator or review date.
 * Psychopedagogy and direction keep seeing everything.
 *
 * Scenario from the comments: Lucía is in 2°A; Mariana López teaches her
 * Matemática, Ana Fernández teaches her Ciencias.
 */
function scopeScenario(): array
{
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $math = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']);
    $science = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Ciencias']);
    $spanish = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Idioma Español']);
    $literature = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Literatura']);

    $mariana = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $mariana, $math);
    $ana = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $ana, $science);

    $lucia = Student::factory()->create(['school_id' => $school->id]);
    $lucia->groups()->attach($group, ['school_year' => now()->year]);

    $calculator = Accommodation::factory()->create([
        'student_id' => $lucia->id,
        'type' => 'Calculadora en evaluaciones',
        'description' => 'detalle clínico de la calculadora',
        'starts_on' => '2026-03-15',
        'assessment_types' => ['written'],
    ]);
    $calculator->subjects()->attach($math);

    $spelling = Accommodation::factory()->create([
        'student_id' => $lucia->id,
        'type' => 'Ortografía no puntúa',
        'starts_on' => '2026-04-02',
    ]);
    $spelling->subjects()->attach([$spanish->id, $literature->id]);

    $seating = Accommodation::factory()->create([
        'student_id' => $lucia->id,
        'type' => 'Sentarla cerca del pizarrón',
        'description' => 'detalle clínico del pizarrón',
        'starts_on' => '2026-03-01',
    ]);

    return compact('school', 'group', 'math', 'science', 'spanish', 'literature', 'mariana', 'ana', 'lucia', 'calculator', 'spelling', 'seating');
}

function chipLabels($response): array
{
    return collect($response->json('data.support_chips.accommodations'))->pluck('label')->sort()->values()->all();
}

it('shows the teacher of the scoped subject the accommodation with its start date and subjects', function () {
    ['mariana' => $mariana, 'lucia' => $lucia, 'math' => $math] = scopeScenario();
    Sanctum::actingAs($mariana);

    $response = $this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertOk();

    expect(chipLabels($response))->toBe(['Calculadora en evaluaciones', 'Sentarla cerca del pizarrón']);

    $calculator = collect($response->json('data.support_chips.accommodations'))
        ->firstWhere('label', 'Calculadora en evaluaciones');
    expect($calculator['starts_on'])->toBe('2026-03-15')
        ->and($calculator['subjects'])->toBe([['id' => $math->id, 'name' => 'Matemática', 'deleted' => false]])
        ->and($calculator['assessment_types'])->toBe(['written']);

    $response->assertJsonPath('data.accommodations_count', 2);
});

it('hides a subject-specific accommodation from the teacher of another subject', function () {
    ['ana' => $ana, 'lucia' => $lucia] = scopeScenario();
    Sanctum::actingAs($ana);

    $response = $this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertOk();

    expect(chipLabels($response))->toBe(['Sentarla cerca del pizarrón']);
    // Not even its existence leaks: the count only covers what she sees.
    $response->assertJsonPath('data.accommodations_count', 1);
    expect($response->getContent())->not->toContain('Calculadora')
        ->not->toContain('Ortografía');
});

it('shows a general accommodation to every teacher of the student, marked as applying in every subject', function () {
    ['mariana' => $mariana, 'ana' => $ana, 'lucia' => $lucia] = scopeScenario();

    foreach ([$mariana, $ana] as $teacher) {
        Sanctum::actingAs($teacher);
        $seating = collect($this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertOk()
            ->json('data.support_chips.accommodations'))
            ->firstWhere('label', 'Sentarla cerca del pizarrón');

        expect($seating)->not->toBeNull()
            ->and($seating['starts_on'])->toBe('2026-03-01')
            ->and($seating['subjects'])->toBe([])
            ->and($seating['assessment_types'])->toBeNull();
    }
});

it('never sends a teacher the origin, validator, review date or clinical detail of an accommodation', function () {
    ['mariana' => $mariana, 'lucia' => $lucia] = scopeScenario();
    Sanctum::actingAs($mariana);

    $response = $this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertOk();

    $response->assertJsonMissingPath('data.accommodations');
    foreach ($response->json('data.support_chips.accommodations') as $chip) {
        expect(array_keys($chip))->toEqualCanonicalizing(['id', 'label', 'starts_on', 'subjects', 'assessment_types']);
    }
    expect($response->getContent())->not->toContain('detalle clínico')
        ->not->toContain('created_by_id')
        ->not->toContain('approved')
        ->not->toContain('barriers_linked')
        ->not->toContain('review');
});

it('keeps showing psychopedagogy and direction every accommodation with its full scope', function () {
    ['school' => $school, 'lucia' => $lucia, 'spanish' => $spanish, 'literature' => $literature] = scopeScenario();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    foreach ([$psychopedagogue, $director] as $user) {
        Sanctum::actingAs($user);
        $response = $this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertOk();

        $response->assertJsonCount(3, 'data.accommodations')
            ->assertJsonPath('data.accommodations_count', 3);
        expect(chipLabels($response))->toHaveCount(3);

        $spelling = collect($response->json('data.accommodations'))->firstWhere('type', 'Ortografía no puntúa');
        expect($spelling['starts_on'])->toBe('2026-04-02')
            ->and(collect($spelling['subjects'])->pluck('id')->sort()->values()->all())
            ->toBe(collect([$spanish->id, $literature->id])->sort()->values()->all());
    }
});

it('hides a scoped accommodation from a teacher who teaches that subject only in another group', function () {
    ['school' => $school, 'lucia' => $lucia, 'group' => $group, 'math' => $math] = scopeScenario();
    // Teaches Matemática elsewhere and Ciencias to Lucía's group.
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    leadGroup(Group::factory()->create(['school_id' => $school->id]), $teacher, $math);
    leadGroup($group, $teacher, Subject::factory()->create(['school_id' => $school->id]));
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertOk();

    expect(chipLabels($response))->toBe(['Sentarla cerca del pizarrón']);
});

it('never lets a teacher of another school see the student or its accommodations', function () {
    ['lucia' => $lucia, 'math' => $math] = scopeScenario();
    $otherSchool = School::factory()->create();
    $outsider = User::factory()->forSchool($otherSchool)->teacher()->create();
    leadGroup(Group::factory()->create(['school_id' => $otherSchool->id]), $outsider);
    Sanctum::actingAs($outsider);

    $this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertNotFound();
    expect(Accommodation::withoutGlobalScopes()->visibleTo($outsider)->count())->toBe(0);
});

it('lets psychopedagogy create an accommodation scoped to subjects and assessment types', function () {
    ['school' => $school, 'lucia' => $lucia, 'math' => $math] = scopeScenario();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $response = $this->postJson("/api/v1/students/{$lucia->id}/accommodations", [
        'category' => 'access',
        'type' => 'Calculadora',
        'description' => 'Puede usar calculadora.',
        'focus_area' => 'mathematics',
        'starts_on' => '2026-05-04',
        'subject_ids' => [$math->id],
        'assessment_types' => ['written', 'oral'],
    ])->assertCreated();

    $response->assertJsonPath('data.starts_on', '2026-05-04')
        ->assertJsonPath('data.subjects.0.id', $math->id)
        ->assertJsonPath('data.assessment_types', ['written', 'oral']);
    $this->assertDatabaseHas('accommodation_subject', [
        'accommodation_id' => $response->json('data.id'),
        'subject_id' => $math->id,
    ]);
});

it('stores a general accommodation when no subjects or assessment types are sent', function () {
    ['school' => $school, 'lucia' => $lucia] = scopeScenario();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $response = $this->postJson("/api/v1/students/{$lucia->id}/accommodations", [
        'category' => 'access',
        'type' => 'Tiempo extendido',
        'description' => 'x',
        'focus_area' => 'attention',
        'starts_on' => '2026-05-04',
        'assessment_types' => [],
    ])->assertCreated();

    $response->assertJsonPath('data.subjects', [])
        ->assertJsonPath('data.assessment_types', null);
});

it('validates the start date format and the scope fields', function () {
    ['school' => $school, 'lucia' => $lucia] = scopeScenario();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$lucia->id}/accommodations", [
        'category' => 'access',
        'type' => 'Tiempo extendido',
        'description' => 'x',
        'focus_area' => 'attention',
        'starts_on' => '02/04/2026',
        'assessment_types' => ['not-a-type'],
        'subject_ids' => ['abc'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_on', 'assessment_types.0', 'subject_ids.0']);
});

it('rejects subjects from another school or deleted ones', function () {
    ['school' => $school, 'lucia' => $lucia] = scopeScenario();
    $director = User::factory()->forSchool($school)->director()->create();
    $foreign = Subject::factory()->create(['school_id' => School::factory()->create()->id]);
    $deleted = Subject::factory()->create(['school_id' => $school->id]);
    $deleted->delete();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$lucia->id}/accommodations", [
        'category' => 'access',
        'type' => 'Tiempo extendido',
        'description' => 'x',
        'focus_area' => 'attention',
        'starts_on' => '2026-05-04',
        'subject_ids' => [$foreign->id, $deleted->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['subject_ids.0', 'subject_ids.1']);

    $this->assertDatabaseMissing('accommodations', ['type' => 'Tiempo extendido']);
});

it('lets psychopedagogy re-scope an accommodation, including back to general', function () {
    ['school' => $school, 'spelling' => $spelling, 'math' => $math] = scopeScenario();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $this->patchJson("/api/v1/accommodations/{$spelling->id}", [
        'category' => 'criteria',
        'subject_ids' => [$math->id],
        'starts_on' => '2026-06-01',
    ])->assertOk()
        ->assertJsonPath('data.subjects.0.id', $math->id)
        ->assertJsonPath('data.starts_on', '2026-06-01');

    $this->patchJson("/api/v1/accommodations/{$spelling->id}", [
        'category' => 'criteria',
        'subject_ids' => [],
    ])->assertOk()->assertJsonPath('data.subjects', []);

    expect($spelling->subjects()->count())->toBe(0);
});

it('leaves the subjects untouched when an edit does not send them', function () {
    ['school' => $school, 'spelling' => $spelling] = scopeScenario();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $this->patchJson("/api/v1/accommodations/{$spelling->id}", ['category' => 'criteria'])
        ->assertOk()
        ->assertJsonCount(2, 'data.subjects');
});

it('forbids a teacher from editing an accommodation', function () {
    ['mariana' => $mariana, 'calculator' => $calculator] = scopeScenario();
    Sanctum::actingAs($mariana);

    $this->patchJson("/api/v1/accommodations/{$calculator->id}", [
        'category' => 'access',
        'subject_ids' => [],
    ])->assertForbidden();

    expect($calculator->subjects()->count())->toBe(1);
});

it('keeps a scoped accommodation scoped when its subject is soft-deleted', function () {
    ['ana' => $ana, 'lucia' => $lucia, 'math' => $math] = scopeScenario();
    $math->delete();
    Sanctum::actingAs($ana);

    $response = $this->getJson("/api/v1/students/{$lucia->id}/tracking")->assertOk();

    expect(chipLabels($response))->toBe(['Sentarla cerca del pizarrón']);
});

it('rejects deactivating, for one assessment, an accommodation scoped to another subject', function () {
    ['ana' => $ana, 'group' => $group, 'science' => $science, 'calculator' => $calculator, 'seating' => $seating] = scopeScenario();
    $assessment = Assessment::factory()->create([
        'school_id' => $group->school_id,
        'group_id' => $group->id,
        'subject_id' => $science->id,
        'teacher_id' => $ana->id,
    ]);
    Sanctum::actingAs($ana);

    $this->postJson("/api/v1/accommodations/{$calculator->id}/instance-overrides", [
        'assessment_id' => $assessment->id,
        'reason' => 'No aplica',
    ])->assertUnprocessable()->assertJsonValidationErrorFor('accommodation');

    $this->postJson("/api/v1/accommodations/{$seating->id}/instance-overrides", [
        'assessment_id' => $assessment->id,
        'reason' => 'Prueba oral en el patio',
    ])->assertCreated();
});

it('keeps accepting, on edit, a subject already attached that was soft-deleted since', function () {
    ['school' => $school, 'calculator' => $calculator, 'math' => $math, 'science' => $science] = scopeScenario();
    $math->delete();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    // The edit form resends every attached subject, the deleted one included.
    $this->patchJson("/api/v1/accommodations/{$calculator->id}", [
        'category' => 'access',
        'subject_ids' => [$math->id, $science->id],
    ])->assertOk()
        ->assertJsonCount(2, 'data.subjects')
        ->assertJsonPath('data.subjects.0.id', $science->id)
        ->assertJsonPath('data.subjects.0.deleted', false)
        ->assertJsonPath('data.subjects.1.id', $math->id)
        ->assertJsonPath('data.subjects.1.deleted', true);

    // …and removing it works too.
    $this->patchJson("/api/v1/accommodations/{$calculator->id}", [
        'category' => 'access',
        'subject_ids' => [$science->id],
    ])->assertOk()->assertJsonCount(1, 'data.subjects');
});

it('still rejects, on edit, a deleted subject that was not attached', function () {
    ['school' => $school, 'spelling' => $spelling] = scopeScenario();
    $deleted = Subject::factory()->create(['school_id' => $school->id]);
    $deleted->delete();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $this->patchJson("/api/v1/accommodations/{$spelling->id}", [
        'category' => 'criteria',
        'subject_ids' => [$deleted->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['subject_ids.0']);
});

it('refuses to hard-delete a subject an accommodation is scoped to', function () {
    ['calculator' => $calculator, 'math' => $math] = scopeScenario();

    // Cascading would silently turn the scoped accommodation into a general one.
    expect(fn () => $math->forceDelete())->toThrow(QueryException::class);
    expect($calculator->subjects()->count())->toBe(1);
});
