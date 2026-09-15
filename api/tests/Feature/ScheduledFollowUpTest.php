<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Attach a student to a group led by the given teacher (current school year),
 * so `User::teachesStudent()` returns true for them.
 */
function teachStudent(User $teacher, Student $student): void
{
    $group = Group::factory()->create(['school_id' => $student->school_id]);
    leadGroup($group, $teacher);
    $student->groups()->attach($group, ['school_year' => now()->year]);
}

// --- create -----------------------------------------------------------------

it('lets a teacher who teaches the student schedule a follow-up', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    teachStudent($teacher, $student);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/students/{$student->id}/scheduled-follow-ups", [
        'description' => 'Revisar si el ajuste de lectura sigue haciendo falta',
        'due_date' => now()->addWeek()->toDateString(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.student_id', $student->id)
        ->assertJsonPath('data.created_by_id', $teacher->id)
        ->assertJsonPath('data.resolved', false)
        ->assertJsonPath('data.is_overdue', false);

    expect(ScheduledFollowUp::where('student_id', $student->id)->count())->toBe(1);
});

it('forbids a teacher who does not teach the student from scheduling a follow-up', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/students/{$student->id}/scheduled-follow-ups", [
        'description' => 'x',
        'due_date' => now()->addWeek()->toDateString(),
    ])->assertForbidden();

    expect(ScheduledFollowUp::count())->toBe(0);
});

it('lets a psychopedagogue and a director schedule a follow-up on any student in the school', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    foreach (['psychopedagogue', 'director'] as $role) {
        $user = User::factory()->forSchool($school)->{$role}()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/students/{$student->id}/scheduled-follow-ups", [
            'description' => 'Confirmar con la familia el resultado de la reunión',
            'due_date' => now()->addDays(3)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.created_by_id', $user->id);
    }

    expect(ScheduledFollowUp::where('student_id', $student->id)->count())->toBe(2);
});

it('validates that description and due_date are required', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$student->id}/scheduled-follow-ups", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['description', 'due_date']);
});

// --- list --------------------------------------------------------------------

it('lists follow-ups for a student, defaulting to all and filtering by ?resolved', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    ScheduledFollowUp::factory()->for($student)->create();
    ScheduledFollowUp::factory()->for($student)->resolved()->create();
    Sanctum::actingAs($director);

    // No query param -> the endpoint returns every follow-up (the default
    // filter is a client concern, not decided in the backend).
    $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups")
        ->assertOk()->assertJsonCount(2, 'data');

    $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups?resolved=false")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.resolved', false);

    $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups?resolved=true")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.resolved', true);
});

it('forbids a teacher who does not teach the student from listing follow-ups', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    ScheduledFollowUp::factory()->for($student)->create();
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups")->assertForbidden();
});

// --- resolve -----------------------------------------------------------------

it('lets a teacher who teaches the student resolve a follow-up with a note', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    teachStudent($teacher, $student);
    $followUp = ScheduledFollowUp::factory()->for($student)->create();
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/scheduled-follow-ups/{$followUp->id}/resolve", [
        'resolution_note' => 'Ya no hace falta el ajuste',
    ])
        ->assertOk()
        ->assertJsonPath('data.resolved', true)
        ->assertJsonPath('data.resolved_by_id', $teacher->id)
        ->assertJsonPath('data.is_overdue', false);

    $fresh = $followUp->fresh();
    expect($fresh->resolved)->toBeTrue()
        ->and($fresh->resolved_by_id)->toBe($teacher->id)
        ->and($fresh->resolved_at)->not->toBeNull()
        ->and($fresh->resolution_note)->toBe('Ya no hace falta el ajuste');
});

it('forbids a teacher who does not teach the student from resolving a follow-up', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $followUp = ScheduledFollowUp::factory()->for($student)->create();
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/scheduled-follow-ups/{$followUp->id}/resolve")->assertForbidden();
    expect($followUp->fresh()->resolved)->toBeFalse();
});

// --- is_overdue --------------------------------------------------------------

it('computes is_overdue server-side across the due date and resolved states', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    // Due yesterday, unresolved -> overdue.
    $overdue = ScheduledFollowUp::factory()->for($student)->overdue()->create();
    // Due yesterday but resolved -> NOT overdue, even though the date passed.
    $resolvedPast = ScheduledFollowUp::factory()->for($student)->overdue()->resolved()->create();
    // Due tomorrow -> NOT overdue.
    $future = ScheduledFollowUp::factory()->for($student)->create([
        'due_date' => now()->addDay()->toDateString(),
    ]);
    // Due today, unresolved -> overdue (due_date <= today).
    $dueToday = ScheduledFollowUp::factory()->for($student)->create([
        'due_date' => now()->toDateString(),
    ]);

    expect($overdue->isOverdue())->toBeTrue()
        ->and($resolvedPast->isOverdue())->toBeFalse()
        ->and($future->isOverdue())->toBeFalse()
        ->and($dueToday->isOverdue())->toBeTrue();

    Sanctum::actingAs($director);
    $byId = collect(
        $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups")->json('data')
    )->keyBy('id');

    expect($byId[$overdue->id]['is_overdue'])->toBeTrue()
        ->and($byId[$resolvedPast->id]['is_overdue'])->toBeFalse()
        ->and($byId[$future->id]['is_overdue'])->toBeFalse()
        ->and($byId[$dueToday->id]['is_overdue'])->toBeTrue();
});

// --- tenant isolation --------------------------------------------------------

it('never exposes or mutates a follow-up from another school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
    $followUpB = ScheduledFollowUp::factory()->for($studentB)->create();
    Sanctum::actingAs($directorA);

    // The cross-tenant student is invisible to the SchoolScope -> 404.
    $this->getJson("/api/v1/students/{$studentB->id}/scheduled-follow-ups")->assertNotFound();
    $this->postJson("/api/v1/students/{$studentB->id}/scheduled-follow-ups", [
        'description' => 'x',
        'due_date' => now()->toDateString(),
    ])->assertNotFound();
    $this->postJson("/api/v1/scheduled-follow-ups/{$followUpB->id}/resolve")->assertNotFound();

    expect($followUpB->fresh()->resolved)->toBeFalse();
});

// --- audit diff redaction ----------------------------------------------------

it('excludes description and resolution_note from the audit diff', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $followUp = ScheduledFollowUp::create([
        'student_id' => $student->id,
        'created_by_id' => $director->id,
        'description' => 'Observación sensible sobre el menor',
        'due_date' => now()->addWeek()->toDateString(),
    ]);

    $createdLog = AuditLog::where('auditable_type', ScheduledFollowUp::class)
        ->where('auditable_id', $followUp->id)
        ->where('action', AuditAction::Created)
        ->firstOrFail();

    // Redacted, not omitted: the field is recorded as having existed, without
    // its sensitive value (CLAUDE.md security rule 11).
    expect($createdLog->changes['description'])->toBe('[excluded]')
        ->and($createdLog->changes['due_date'])->not->toBe('[excluded]');

    $followUp->update([
        'resolved' => true,
        'resolved_by_id' => $director->id,
        'resolved_at' => now(),
        'resolution_note' => 'Detalle sensible de la resolución',
    ]);

    $updatedLog = AuditLog::where('auditable_type', ScheduledFollowUp::class)
        ->where('auditable_id', $followUp->id)
        ->where('action', AuditAction::Updated)
        ->firstOrFail();

    expect($updatedLog->changes['resolution_note'])->toBe(['changed' => true])
        ->and($updatedLog->changes['resolved'])->toBe(['before' => false, 'after' => true]);
});
