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
    // Due today, unresolved -> NOT overdue yet; today is still the last valid
    // day (due_date < today), so it becomes overdue tomorrow.
    $dueToday = ScheduledFollowUp::factory()->for($student)->create([
        'due_date' => now()->toDateString(),
    ]);

    expect($overdue->isOverdue())->toBeTrue()
        ->and($resolvedPast->isOverdue())->toBeFalse()
        ->and($future->isOverdue())->toBeFalse()
        ->and($dueToday->isOverdue())->toBeFalse();

    Sanctum::actingAs($director);
    $byId = collect(
        $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups")->json('data')
    )->keyBy('id');

    expect($byId[$overdue->id]['is_overdue'])->toBeTrue()
        ->and($byId[$resolvedPast->id]['is_overdue'])->toBeFalse()
        ->and($byId[$future->id]['is_overdue'])->toBeFalse()
        ->and($byId[$dueToday->id]['is_overdue'])->toBeFalse();
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

// --- responsible person + sharing ------------------------------------------

it('makes the creator the responsible person by default', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    teachStudent($teacher, $student);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/students/{$student->id}/scheduled-follow-ups", [
        'description' => 'x',
        'due_date' => now()->addWeek()->toDateString(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.assigned_to_id', $teacher->id)
        ->assertJsonPath('data.shared_with', []);

    expect($teacher->unreadNotifications()->count())->toBe(0);
});

it('lets a teacher assign a follow-up to a psychopedagogue and share it, notifying the responsible', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $psico = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    teachStudent($teacher, $student);
    leadGroup($student->groups()->first(), $otherTeacher);
    Sanctum::actingAs($teacher);

    $response = $this->postJson("/api/v1/students/{$student->id}/scheduled-follow-ups", [
        'description' => 'Revisar si el tiempo extendido le sirve',
        'due_date' => now()->addWeek()->toDateString(),
        'assigned_to_id' => $psico->id,
        'shared_with_ids' => [$otherTeacher->id, $psico->id],
    ])->assertCreated()
        ->assertJsonPath('data.assigned_to_id', $psico->id)
        ->assertJsonPath('data.assigned_to.role', 'psychopedagogue')
        ->assertJsonCount(1, 'data.shared_with')
        ->assertJsonPath('data.shared_with.0.id', $otherTeacher->id);

    $followUpId = $response->json('data.id');

    // Notification (a notice, not an alert) goes to the responsible only, with ids only.
    expect($psico->unreadNotifications()->count())->toBe(1)
        ->and($otherTeacher->unreadNotifications()->count())->toBe(0)
        ->and($psico->unreadNotifications()->first()->data)->not->toHaveKey('description');

    // Pending list: responsible and sharer see it, direction does not.
    Sanctum::actingAs($psico);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()
        ->assertJsonPath('data.0.id', $followUpId)
        ->assertJsonPath('data.0.student.full_name', $student->full_name);
    Sanctum::actingAs($otherTeacher);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(1, 'data');
    Sanctum::actingAs($director);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(0, 'data');
    // ...but can still see it from the student's record.
    $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups")->assertOk()->assertJsonCount(1, 'data');
});

it('rejects assigning to or sharing with someone who cannot see the student', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $strangerTeacher = User::factory()->forSchool($school)->teacher()->create();
    $outsider = User::factory()->forSchool(School::factory()->create())->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    teachStudent($teacher, $student);
    Sanctum::actingAs($teacher);
    $url = "/api/v1/students/{$student->id}/scheduled-follow-ups";
    $base = ['description' => 'x', 'due_date' => now()->addWeek()->toDateString()];

    $this->postJson($url, $base + ['assigned_to_id' => $strangerTeacher->id])->assertJsonValidationErrors('assigned_to_id');
    $this->postJson($url, $base + ['assigned_to_id' => $outsider->id])->assertJsonValidationErrors('assigned_to_id');
    $this->postJson($url, $base + ['shared_with_ids' => [$outsider->id]])->assertJsonValidationErrors('shared_with_ids.0');
    $this->postJson($url, $base + ['assigned_to_id' => 999999])->assertJsonValidationErrors('assigned_to_id');

    // A deactivated colleague can no longer log in: not a valid responsible/sharer.
    $disabledPsico = User::factory()->forSchool($school)->psychopedagogue()->create(['disabled_at' => now()]);
    $this->postJson($url, $base + ['assigned_to_id' => $disabledPsico->id])->assertJsonValidationErrors('assigned_to_id');
    $this->postJson($url, $base + ['shared_with_ids' => [$disabledPsico->id]])->assertJsonValidationErrors('shared_with_ids.0');

    expect(ScheduledFollowUp::count())->toBe(0)
        ->and($outsider->notifications()->count())->toBe(0)
        ->and($disabledPsico->notifications()->count())->toBe(0);
});

it('drops a shared follow-up from the pending list once the person loses access to the student', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    teachStudent($teacher, $student);
    $group = $student->groups()->first();
    leadGroup($group, $otherTeacher);
    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/students/{$student->id}/scheduled-follow-ups", [
        'description' => 'x',
        'due_date' => now()->addWeek()->toDateString(),
        'shared_with_ids' => [$otherTeacher->id],
    ])->assertCreated();

    Sanctum::actingAs($otherTeacher);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(1, 'data');

    // Being shared on a follow-up grants no access of its own: once the
    // teacher no longer teaches the student, both the list and the record close.
    $group->teachers()->detach($otherTeacher->id);
    $this->getJson('/api/v1/scheduled-follow-ups/mine')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/students/{$student->id}/scheduled-follow-ups")->assertForbidden();
    $this->getJson("/api/v1/students/{$student->id}/follow-up-candidates")->assertForbidden();
});

it('lists follow-up candidates filtered by role, only people who see the student', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $strangerTeacher = User::factory()->forSchool($school)->teacher()->create();
    $psico = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    teachStudent($teacher, $student);
    Sanctum::actingAs($teacher);

    $ids = fn ($response) => collect($response->json('data'))->pluck('id')->all();

    $teachers = $this->getJson("/api/v1/students/{$student->id}/follow-up-candidates?role=teacher")->assertOk();
    expect($ids($teachers))->toBe([$teacher->id])->and($ids($teachers))->not->toContain($strangerTeacher->id);

    $psicos = $this->getJson("/api/v1/students/{$student->id}/follow-up-candidates?role=psychopedagogue")->assertOk();
    expect($ids($psicos))->toBe([$psico->id]);
    expect(array_keys($psicos->json('data.0')))->toEqualCanonicalizing(['id', 'name', 'role']);

    $this->getJson("/api/v1/students/{$student->id}/follow-up-candidates?role=hacker")->assertUnprocessable();

    // Deactivated colleagues and other schools' staff are never offered.
    $disabledPsico = User::factory()->forSchool($school)->psychopedagogue()->create(['disabled_at' => now()]);
    User::factory()->forSchool(School::factory()->create())->psychopedagogue()->create();
    $all = $this->getJson("/api/v1/students/{$student->id}/follow-up-candidates")->assertOk();
    expect($ids($all))->toEqualCanonicalizing([$teacher->id, $psico->id])
        ->and($ids($all))->not->toContain($disabledPsico->id);

    Sanctum::actingAs($strangerTeacher);
    $this->getJson("/api/v1/students/{$student->id}/follow-up-candidates")->assertForbidden();
});

it('serves and marks read only the caller\'s own notifications', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psico = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    teachStudent($teacher, $student);
    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/students/{$student->id}/scheduled-follow-ups", [
        'description' => 'x',
        'due_date' => now()->addWeek()->toDateString(),
        'assigned_to_id' => $psico->id,
    ])->assertCreated();
    $notificationId = $psico->unreadNotifications()->first()->id;

    $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/notifications/{$notificationId}/read")->assertNotFound();
    $this->postJson('/api/v1/notifications/not-a-uuid/read')->assertNotFound();

    Sanctum::actingAs($psico);
    $this->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.data.student_id', $student->id);
    $this->postJson("/api/v1/notifications/{$notificationId}/read")->assertOk();
    $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
});
