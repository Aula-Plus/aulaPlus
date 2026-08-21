<?php

use App\Enums\AuditAction;
use App\Models\Accommodation;
use App\Models\AuditLog;
use App\Models\Barrier;
use App\Models\School;
use App\Models\Student;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

it('records an audit log entry with the correct diff on create, update and delete of an accommodation', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $accommodation = Accommodation::factory()->create([
        'student_id' => $student->id,
        'description' => 'Needs extra time on written exams',
        'focus_area' => 'mathematics',
    ]);

    $createdLog = AuditLog::where('auditable_type', Accommodation::class)
        ->where('auditable_id', $accommodation->id)
        ->where('action', AuditAction::Created)
        ->firstOrFail();

    // description is on Accommodation::$auditableExcludeFromDiff (CLAUDE.md
    // rule 11: never log sensitive clinical content in full) — redacted, not
    // omitted; ordinary fields are recorded as a full snapshot.
    expect($createdLog->user_id)->toBe($director->id)
        ->and($createdLog->origin->value)->toBe('user')
        ->and($createdLog->school_id)->toBe($school->id)
        ->and($createdLog->changes['description'])->toBe('[excluded]')
        ->and($createdLog->changes['focus_area'])->toBe('mathematics');

    $accommodation->update(['focus_area' => 'literacy']);

    $updatedLog = AuditLog::where('auditable_type', Accommodation::class)
        ->where('auditable_id', $accommodation->id)
        ->where('action', AuditAction::Updated)
        ->firstOrFail();

    expect($updatedLog->changes)->toBe([
        'focus_area' => ['before' => 'mathematics', 'after' => 'literacy'],
    ]);

    $accommodation->delete();

    $deletedLog = AuditLog::where('auditable_type', Accommodation::class)
        ->where('auditable_id', $accommodation->id)
        ->where('action', AuditAction::Deleted)
        ->firstOrFail();

    expect($deletedLog->changes['focus_area'])->toBe('literacy')
        ->and($deletedLog->changes['description'])->toBe('[excluded]');
});

it('excludes summary and document_url from the diff when a technical report is created', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psychopedagogue);

    $report = TechnicalReport::create([
        'student_id' => $student->id,
        'document_url' => 'reports/private/abc123.pdf',
        'summary' => 'Detailed clinical summary about the student',
    ]);

    $log = AuditLog::where('auditable_type', TechnicalReport::class)
        ->where('auditable_id', $report->id)
        ->firstOrFail();

    expect($log->changes['summary'])->toBe('[excluded]')
        ->and($log->changes['document_url'])->toBe('[excluded]')
        ->and($report->uploaded_by_id)->toBe($psychopedagogue->id);
});

it('records origin=system and a null user_id when there is no authenticated user', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    // Simulates a queued Job (e.g. the session-5 AI assistant applying a
    // proposal): created_by_id is still a real user (the model's own FK is
    // NOT NULL), but there is no *authenticated* user in this context, so
    // the audit log's actor (user_id/origin) must reflect "system", not
    // silently attribute the action to created_by_id's user.
    $onBehalfOf = User::factory()->forSchool($school)->teacher()->create();

    try {
        Tenancy::useSchool($school);

        $barrier = Barrier::create([
            'student_id' => $student->id,
            'description' => 'A barrier created by a system job',
            'active' => true,
            'created_by_id' => $onBehalfOf->id,
        ]);

        $log = AuditLog::where('auditable_type', Barrier::class)
            ->where('auditable_id', $barrier->id)
            ->firstOrFail();

        expect($log->user_id)->toBeNull()
            ->and($log->origin->value)->toBe('system');
    } finally {
        Tenancy::forget();
    }
});
