<?php

use App\Enums\AuditAction;
use App\Models\Accommodation;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns 422 when approving an accommodation that does not require external approval', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'requires_external_approval' => false,
    ]);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/approve")
        ->assertStatus(422);

    expect($accommodation->fresh()->approved)->toBeNull();
});

it('returns 422 when approving an accommodation that was already decided', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'requires_external_approval' => true,
        'approved' => true,
    ]);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/approve")->assertStatus(422);
    $this->postJson("/api/v1/accommodations/{$accommodation->id}/reject")->assertStatus(422);
});

it('lets a director approve a pending accommodation and records who approved it', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'requires_external_approval' => true,
        'approved' => null,
    ]);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.approved', true)
        ->assertJsonPath('data.is_effective', true);

    expect($accommodation->fresh()->approved)->toBeTrue();
});

it('lets a psychopedagogue reject a pending accommodation', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'requires_external_approval' => true,
        'approved' => null,
    ]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.approved', false)
        ->assertJsonPath('data.is_effective', false);

    expect($accommodation->fresh()->approved)->toBeFalse();
});

it('rejects a teacher trying to approve an accommodation', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'requires_external_approval' => true,
        'approved' => null,
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/approve")->assertForbidden();
});

it('records an audit_logs entry for the approval', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'requires_external_approval' => true,
        'approved' => null,
    ]);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/accommodations/{$accommodation->id}/approve")->assertOk();

    $log = AuditLog::where('auditable_type', Accommodation::class)
        ->where('auditable_id', $accommodation->id)
        ->where('action', AuditAction::Updated)
        ->firstOrFail();

    expect($log->changes)->toBe(['approved' => ['before' => null, 'after' => true]])
        ->and($log->user_id)->toBe($director->id);
});
