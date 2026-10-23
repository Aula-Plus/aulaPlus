<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\School;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestResult;
use App\Models\ScreeningTestType;
use App\Models\Student;
use App\Models\User;
use App\Notifications\ScreeningDesignChangedNotification;
use App\Notifications\ScreeningDesignRevertedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * Catalog + design authoring/approval (docs/prompts/11-pruebas-de-sondeo.md
 * §§1, 4).
 */
it('lets psychopedagogy create a type and returns its (absent) current design', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $this->postJson('/api/v1/screening-test-types', ['name' => 'Comprensión lectora 2do ciclo'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Comprensión lectora 2do ciclo')
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.current_design', null)
        ->assertJsonPath('data.created_by_id', $psychopedagogue->id);

    $this->assertDatabaseHas('screening_test_types', [
        'school_id' => $school->id,
        'name' => 'Comprensión lectora 2do ciclo',
        'created_by_id' => $psychopedagogue->id,
    ]);
});

it('stores the optional area of a type and validates its length', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());

    $this->postJson('/api/v1/screening-test-types', ['name' => 'TECLE', 'area' => 'lectoescritura'])
        ->assertCreated()
        ->assertJsonPath('data.area', 'lectoescritura');

    $this->postJson('/api/v1/screening-test-types', ['name' => 'BADyG'])
        ->assertCreated()
        ->assertJsonPath('data.area', null);

    $this->postJson('/api/v1/screening-test-types', ['name' => 'X', 'area' => str_repeat('a', 101)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('area');
});

it('forbids a teacher and a director from creating a type', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    Sanctum::actingAs($teacher);
    $this->postJson('/api/v1/screening-test-types', ['name' => 'X'])->assertForbidden();

    Sanctum::actingAs($director);
    $this->postJson('/api/v1/screening-test-types', ['name' => 'X'])->assertForbidden();
});

it('forbids a teacher from listing types', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    ScreeningTestType::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson('/api/v1/screening-test-types')->assertForbidden();
});

it('exposes the current approved design (most recent approved) on the type', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);

    // An older approved design, a rejected one, and a newer approved one.
    ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['cutoff_low' => 10, 'cutoff_high' => 20]);
    ScreeningTestDesign::factory()->for($type, 'type')->rejected()->create(['cutoff_low' => 30, 'cutoff_high' => 40]);
    $newest = ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['cutoff_low' => 50, 'cutoff_high' => 60]);
    // A pending one newer still must NOT be the current design.
    ScreeningTestDesign::factory()->for($type, 'type')->create(['cutoff_low' => 70, 'cutoff_high' => 80]);

    Sanctum::actingAs($psychopedagogue);

    $this->getJson('/api/v1/screening-test-types')
        ->assertOk()
        ->assertJsonPath('data.0.current_design.id', $newest->id);
});

/**
 * @return list<array<string, mixed>>
 */
function threeBands(float $low = 34, float $mid = 64): array
{
    return [
        ['name' => 'Descendido', 'color' => 'red', 'max' => $low, 'meaning' => 'Conviene revisarlo con el equipo.'],
        ['name' => 'En proceso', 'color' => 'yellow', 'max' => $mid, 'meaning' => 'Se vuelve a mirar en la próxima toma.'],
        ['name' => 'Adecuado', 'color' => 'green', 'max' => 100, 'meaning' => 'No requiere acción.'],
    ];
}

it('lets psychopedagogy save a design that rules at once and notifies Dirección', function () {
    Notification::fake();
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $otherSchoolDirector = User::factory()->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id, 'name' => 'Prueba de atención']);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", [
        'bands' => threeBands(),
        'reason' => 'Subir el corte de Adecuado.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.approved', true)
        ->assertJsonPath('data.bands.1.name', 'En proceso')
        ->assertJsonPath('data.reason', 'Subir el corte de Adecuado.')
        ->assertJsonPath('data.created_by_id', $psychopedagogue->id);

    $this->getJson('/api/v1/screening-test-types')
        ->assertJsonPath('data.0.current_design.bands.0.name', 'Descendido');

    Notification::assertSentTo($director, ScreeningDesignChangedNotification::class, function ($notification) use ($director, $type) {
        $data = $notification->toArray($director);

        return $data['type'] === 'screening_design_changed'
            && $data['screening_test_type_id'] === $type->id
            && $data['screening_test_type_name'] === 'Prueba de atención';
    });
    Notification::assertNotSentTo($otherSchoolDirector, ScreeningDesignChangedNotification::class);
    Notification::assertNotSentTo($psychopedagogue, ScreeningDesignChangedNotification::class);
});

it('stores the notice for Dirección so it reaches a later session', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);

    Sanctum::actingAs($psychopedagogue);
    $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", ['bands' => threeBands()])->assertCreated();

    Sanctum::actingAs($director);
    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.data.type', 'screening_design_changed')
        ->assertJsonPath('data.0.data.screening_test_type_id', $type->id);
});

it('does not keep a ruling design when its notice to Dirección cannot be stored', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('notifications table down'));
    Sanctum::actingAs($psychopedagogue);

    $this->withoutExceptionHandling();
    expect(fn () => $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", ['bands' => threeBands()]))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('screening_test_designs', 0);
});

it('lets Dirección save a design too, and forbids a teacher', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", ['bands' => threeBands()])->assertForbidden();

    Sanctum::actingAs($director);
    $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", ['bands' => threeBands()])->assertCreated();
});

it('accepts 2 to 5 bands, each with a name, a colour and a mandatory meaning', function (array $bands, ?string $errorKey) {
    $school = School::factory()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());

    $response = $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", ['bands' => $bands]);

    $errorKey === null
        ? $response->assertCreated()
        : $response->assertUnprocessable()->assertJsonValidationErrors($errorKey);
})->with([
    'two bands, open-ended last' => [[
        ['name' => 'Bajo', 'color' => 'red', 'max' => 40, 'meaning' => 'a'],
        ['name' => 'Alto', 'color' => 'green', 'max' => null, 'meaning' => 'b'],
    ], null],
    'five bands' => [[
        ['name' => 'A', 'color' => 'red', 'max' => 10, 'meaning' => 'a'],
        ['name' => 'B', 'color' => 'orange', 'max' => 20, 'meaning' => 'b'],
        ['name' => 'C', 'color' => 'yellow', 'max' => 30, 'meaning' => 'c'],
        ['name' => 'D', 'color' => 'green', 'max' => 40, 'meaning' => 'd'],
        ['name' => 'E', 'color' => 'blue', 'max' => 50, 'meaning' => 'e'],
    ], null],
    'one band' => [[['name' => 'A', 'color' => 'red', 'max' => 10, 'meaning' => 'a']], 'bands'],
    'six bands' => [array_fill(0, 6, ['name' => 'A', 'color' => 'red', 'max' => 10, 'meaning' => 'a']), 'bands'],
    'missing meaning' => [[
        ['name' => 'Bajo', 'color' => 'red', 'max' => 40, 'meaning' => ''],
        ['name' => 'Alto', 'color' => 'green', 'max' => 100, 'meaning' => 'b'],
    ], 'bands.0.meaning'],
    'unknown colour' => [[
        ['name' => 'Bajo', 'color' => 'purple', 'max' => 40, 'meaning' => 'a'],
        ['name' => 'Alto', 'color' => 'green', 'max' => 100, 'meaning' => 'b'],
    ], 'bands.0.color'],
    'max not going up' => [[
        ['name' => 'Bajo', 'color' => 'red', 'max' => 40, 'meaning' => 'a'],
        ['name' => 'Medio', 'color' => 'yellow', 'max' => 40, 'meaning' => 'b'],
        ['name' => 'Alto', 'color' => 'green', 'max' => 100, 'meaning' => 'c'],
    ], 'bands.1.max'],
    'max missing on a middle band' => [[
        ['name' => 'Bajo', 'color' => 'red', 'max' => null, 'meaning' => 'a'],
        ['name' => 'Alto', 'color' => 'green', 'max' => 100, 'meaning' => 'b'],
    ], 'bands.0.max'],
]);

it('lets Dirección revert the design in force so the previous one rules again', function () {
    Notification::fake();
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    $previous = ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['bands' => threeBands(34, 59)]);
    $current = ScreeningTestDesign::factory()->for($type, 'type')->approved()->create([
        'bands' => threeBands(34, 64),
        'created_by_id' => $psychopedagogue->id,
    ]);

    Sanctum::actingAs($psychopedagogue);
    $this->postJson("/api/v1/screening-test-designs/{$current->id}/revert")->assertForbidden();

    Sanctum::actingAs($director);
    $this->postJson("/api/v1/screening-test-designs/{$current->id}/revert")
        ->assertOk()
        ->assertJsonPath('data.reverted_by_id', $director->id);

    $this->getJson('/api/v1/screening-test-types')
        ->assertJsonPath('data.0.current_design.id', $previous->id)
        ->assertJsonPath('data.0.previous_design', null);

    // The reverted design stays in the history.
    $this->getJson("/api/v1/screening-test-types/{$type->id}/designs")
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $current->id)
        ->assertJsonPath('data.0.reverted_by_name', $director->name);

    Notification::assertSentTo($psychopedagogue, ScreeningDesignRevertedNotification::class);
});

it('returns 422 when reverting a design that is not in force or has nothing before it', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    $first = ScreeningTestDesign::factory()->for($type, 'type')->approved()->create();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/screening-test-designs/{$first->id}/revert")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('design');

    ScreeningTestDesign::factory()->for($type, 'type')->approved()->create();

    $this->postJson("/api/v1/screening-test-designs/{$first->id}/revert")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('design');
});

it('previews the impact counting the latest take of each student, without names', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['bands' => threeBands(34, 59)]);
    $group = Group::factory()->create(['school_id' => $school->id]);
    $older = ScreeningTestApplication::factory()->create([
        'school_id' => $school->id, 'screening_test_type_id' => $type->id, 'group_id' => $group->id, 'application_date' => '2026-03-01',
    ]);
    $newer = ScreeningTestApplication::factory()->create([
        'school_id' => $school->id, 'screening_test_type_id' => $type->id, 'group_id' => $group->id, 'application_date' => '2026-08-01',
    ]);
    $ana = Student::factory()->create(['school_id' => $school->id]);
    $beto = Student::factory()->create(['school_id' => $school->id]);
    // Ana: 20 in March, 62 in August — only the August take counts.
    ScreeningTestResult::factory()->create(['school_id' => $school->id, 'screening_test_application_id' => $older->id, 'student_id' => $ana->id, 'code' => 'A1', 'score' => 20]);
    ScreeningTestResult::factory()->create(['school_id' => $school->id, 'screening_test_application_id' => $newer->id, 'student_id' => $ana->id, 'code' => 'B1', 'score' => 62]);
    ScreeningTestResult::factory()->create(['school_id' => $school->id, 'screening_test_application_id' => $newer->id, 'student_id' => $beto->id, 'code' => 'B2', 'score' => 90]);
    // Not loaded yet: does not count.
    ScreeningTestResult::factory()->create(['school_id' => $school->id, 'screening_test_application_id' => $newer->id, 'student_id' => Student::factory()->create(['school_id' => $school->id])->id, 'code' => 'B3', 'score' => null]);
    Sanctum::actingAs($psychopedagogue);

    $response = $this->postJson("/api/v1/screening-test-types/{$type->id}/impact", ['bands' => threeBands(34, 64)])
        ->assertOk()
        ->assertJsonPath('data.students', 2)
        // With the cutoffs in force (59), Ana's 62 is "Adecuado"…
        ->assertJsonPath('data.current.1.count', 0)
        ->assertJsonPath('data.current.2.count', 2)
        // …with the proposed ones (64) she moves to "En proceso".
        ->assertJsonPath('data.proposed.1.count', 1)
        ->assertJsonPath('data.proposed.2.count', 1);

    expect($response->getContent())->not->toContain($ana->full_name)->not->toContain('B1');
});

it('forbids a teacher from previewing the impact', function () {
    $school = School::factory()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());

    $this->postJson("/api/v1/screening-test-types/{$type->id}/impact", ['bands' => threeBands()])->assertForbidden();
});

it('lets Psicopedagogía and Dirección edit the general data, keeping the history on rename', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id, 'name' => 'TECLE']);
    Sanctum::actingAs($director);

    $this->patchJson("/api/v1/screening-test-types/{$type->id}", [
        'name' => 'Comprensión lectora',
        'levels' => ['lower_secondary', 'upper_secondary'],
        'frequency' => 'every_few_years',
        'frequency_years' => 3,
        'teacher_visible' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $type->id)
        ->assertJsonPath('data.name', 'Comprensión lectora')
        ->assertJsonPath('data.levels', ['lower_secondary', 'upper_secondary'])
        ->assertJsonPath('data.frequency', 'every_few_years')
        ->assertJsonPath('data.frequency_years', 3)
        ->assertJsonPath('data.teacher_visible', true);

    $log = AuditLog::query()
        ->where('auditable_type', ScreeningTestType::class)
        ->where('auditable_id', $type->id)
        ->where('action', AuditAction::Updated)
        ->latest('id')
        ->first();
    expect($log->changes['name'])->toBe(['before' => 'TECLE', 'after' => 'Comprensión lectora']);

    // Switching to a yearly frequency drops the "every N years" value.
    $this->patchJson("/api/v1/screening-test-types/{$type->id}", ['frequency' => 'yearly'])
        ->assertOk()
        ->assertJsonPath('data.frequency_years', null);
});

it('validates the general data and keeps teacher access off by default', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $this->postJson('/api/v1/screening-test-types', ['name' => 'X'])
        ->assertCreated()
        ->assertJsonPath('data.teacher_visible', false)
        ->assertJsonPath('data.levels', []);

    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    $this->patchJson("/api/v1/screening-test-types/{$type->id}", ['levels' => ['kindergarten']])
        ->assertUnprocessable()->assertJsonValidationErrors('levels.0');
    $this->patchJson("/api/v1/screening-test-types/{$type->id}", ['frequency' => 'every_few_years'])
        ->assertUnprocessable()->assertJsonValidationErrors('frequency_years');
});

it('forbids a teacher and another school from editing the general data', function () {
    $school = School::factory()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);

    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());
    $this->patchJson("/api/v1/screening-test-types/{$type->id}", ['name' => 'X'])->assertForbidden();

    Sanctum::actingAs(User::factory()->director()->create());
    $this->patchJson("/api/v1/screening-test-types/{$type->id}", ['name' => 'X'])->assertNotFound();
});

// docs/prompts/11-pruebas-de-sondeo.md §1: the type listing carries each type's
// current design. Regression — resolving that design must not cost one query
// per type (N+1); the endpoint eager-loads the approved designs instead.
it('lists types with their current design without an N+1', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);

    $makeTypeWithDesign = function () use ($school): void {
        $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
        ScreeningTestDesign::factory()->for($type, 'type')->approved()->create();
    };

    $makeTypeWithDesign();

    // Warm up per-request caches (authenticated user's roles, etc.) so the
    // measurements below capture only the listing's own queries.
    DB::connection()->enableQueryLog();
    $this->getJson('/api/v1/screening-test-types')->assertOk();

    DB::connection()->flushQueryLog();
    $this->getJson('/api/v1/screening-test-types')->assertOk()->assertJsonCount(1, 'data');
    $countForOne = count(DB::connection()->getQueryLog());

    $makeTypeWithDesign();
    $makeTypeWithDesign();

    DB::connection()->flushQueryLog();
    $this->getJson('/api/v1/screening-test-types')->assertOk()->assertJsonCount(3, 'data');
    $countForThree = count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();

    // Constant query count regardless of the number of types => no N+1.
    expect($countForThree)->toBe($countForOne);
});
