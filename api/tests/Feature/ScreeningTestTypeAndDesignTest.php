<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use App\Models\User;
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

it('lets psychopedagogy author a design that lands pending', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", [
        'cutoff_low' => 40,
        'cutoff_high' => 70,
        'meaning_red' => 'Apoyo intensivo',
        'meaning_yellow' => 'Seguimiento',
        'meaning_green' => 'Esperado',
    ])
        ->assertCreated()
        ->assertJsonPath('data.approved', null)
        ->assertJsonPath('data.created_by_id', $psychopedagogue->id);
});

it('rejects a design whose high cutoff is below the low cutoff', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", [
        'cutoff_low' => 70,
        'cutoff_high' => 40,
        'meaning_red' => 'a',
        'meaning_yellow' => 'b',
        'meaning_green' => 'c',
    ])->assertStatus(422);
});

it('forbids a teacher and a director from authoring a design', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    $payload = [
        'cutoff_low' => 40, 'cutoff_high' => 70,
        'meaning_red' => 'a', 'meaning_yellow' => 'b', 'meaning_green' => 'c',
    ];

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", $payload)->assertForbidden();

    Sanctum::actingAs($director);
    $this->postJson("/api/v1/screening-test-types/{$type->id}/designs", $payload)->assertForbidden();
});

it('lets a director approve a pending design and records who approved it', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    $design = ScreeningTestDesign::factory()->for($type, 'type')->create();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/screening-test-designs/{$design->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.approved', true)
        ->assertJsonPath('data.approved_by_id', $director->id);

    expect($design->fresh()->approved)->toBeTrue();

    $log = AuditLog::where('auditable_type', ScreeningTestDesign::class)
        ->where('auditable_id', $design->id)
        ->where('action', AuditAction::Updated)
        ->firstOrFail();

    expect($log->changes['approved'])->toBe(['before' => null, 'after' => true])
        ->and($log->user_id)->toBe($director->id);
});

it('lets a director reject a pending design', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    $design = ScreeningTestDesign::factory()->for($type, 'type')->create();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/screening-test-designs/{$design->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.approved', false)
        ->assertJsonPath('data.approved_by_id', $director->id);
});

it('forbids psychopedagogy and teacher from approving a design (director only)', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    $design = ScreeningTestDesign::factory()->for($type, 'type')->create();

    Sanctum::actingAs($psychopedagogue);
    $this->postJson("/api/v1/screening-test-designs/{$design->id}/approve")->assertForbidden();

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/screening-test-designs/{$design->id}/reject")->assertForbidden();
});

it('returns 422 when approving a design that was already decided', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    $design = ScreeningTestDesign::factory()->for($type, 'type')->approved()->create();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/screening-test-designs/{$design->id}/approve")->assertStatus(422);
    $this->postJson("/api/v1/screening-test-designs/{$design->id}/reject")->assertStatus(422);
});
