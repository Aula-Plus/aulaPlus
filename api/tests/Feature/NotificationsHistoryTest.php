<?php

use App\Models\Accommodation;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AccommodationConfirmedNotification;
use Laravel\Sanctum\Sanctum;

/**
 * Screen 22 «Notificaciones» (ClickUp 1dfd3mc4bgw): each person's notice
 * history (read ones included), «Marcar todas como leídas», and the notice a
 * teacher gets when a new accommodation for a student of theirs is in force.
 */
function noticesScenario(): array
{
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $math = leadGroup($group, $teacher);
    $language = leadGroup($group, $otherTeacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    $outsider = User::factory()->forSchool($school)->teacher()->create();

    return compact('school', 'director', 'psycho', 'teacher', 'otherTeacher', 'group', 'math', 'language', 'student', 'outsider');
}

function noticeAccommodationPayload(array $overrides = []): array
{
    return [
        'category' => 'access',
        'type' => 'Tiempo extendido 25%',
        'description' => 'Diagnóstico reservado: no tiene que viajar en el aviso.',
        'focus_area' => 'Escritura',
        ...$overrides,
    ];
}

it('notifies every teacher of the student when psychopedagogy registers a general accommodation, without its content', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'otherTeacher' => $otherTeacher, 'student' => $student, 'outsider' => $outsider, 'director' => $director] = noticesScenario();

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/students/{$student->id}/accommodations", noticeAccommodationPayload())->assertCreated();

    foreach ([$teacher, $otherTeacher] as $recipient) {
        $stored = $recipient->unreadNotifications()->sole();
        expect($stored->type)->toBe(AccommodationConfirmedNotification::class);
        // Ids only: never the type, the description nor the student's name.
        expect(json_encode($stored->data))
            ->not->toContain('Diagnóstico')
            ->not->toContain('Tiempo extendido')
            ->not->toContain($student->full_name);

        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.data.type', 'accommodation_confirmed')
            ->assertJsonPath('data.0.data.student_id', $student->id)
            ->assertJsonPath('data.0.data.student_name', $student->full_name)
            ->assertJsonPath('data.0.read_at', null);
    }

    // Nobody outside the student's groups, nor the author, nor direction.
    expect($outsider->notifications()->count())->toBe(0)
        ->and($psycho->notifications()->count())->toBe(0)
        ->and($director->notifications()->count())->toBe(0);
});

it('only notifies the teachers of the subjects a scoped accommodation applies to', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'otherTeacher' => $otherTeacher, 'student' => $student, 'math' => $math] = noticesScenario();

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/students/{$student->id}/accommodations", noticeAccommodationPayload([
        'subject_ids' => [$math->id],
    ]))->assertCreated();

    expect($teacher->notifications()->count())->toBe(1)
        ->and($otherTeacher->notifications()->count())->toBe(0);
});

it('notifies the teachers only when psychopedagogy confirms a direction proposal, not when it is proposed', function () {
    ['psycho' => $psycho, 'director' => $director, 'teacher' => $teacher, 'student' => $student] = noticesScenario();

    Sanctum::actingAs($director);
    $id = $this->postJson("/api/v1/students/{$student->id}/accommodations", noticeAccommodationPayload([
        'review_due_date' => now()->addDays(7)->toDateString(),
        'review_recipients' => 'role',
    ]))->assertCreated()->json('data.id');

    expect($teacher->notifications()->count())->toBe(0);

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/accommodations/{$id}/proposal/confirm")->assertOk();

    expect($teacher->unreadNotifications()->sole()->data)->toMatchArray([
        'type' => 'accommodation_confirmed',
        'accommodation_id' => $id,
    ]);
});

it('does not notify for an accommodation registered switched off', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = noticesScenario();

    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/students/{$student->id}/accommodations", noticeAccommodationPayload(['active' => false]))
        ->assertCreated();

    expect($teacher->notifications()->count())->toBe(0);
});

it('lists the history with read notices when asked for all, and only unread ones by default', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'student' => $student] = noticesScenario();
    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/students/{$student->id}/accommodations", noticeAccommodationPayload(['type' => 'Uno']))->assertCreated();
    $this->postJson("/api/v1/students/{$student->id}/accommodations", noticeAccommodationPayload(['type' => 'Dos']))->assertCreated();

    Sanctum::actingAs($teacher);
    $first = $teacher->unreadNotifications()->oldest()->first();
    $this->postJson("/api/v1/notifications/{$first->id}/read")->assertOk();

    $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
    $all = $this->getJson('/api/v1/notifications?filter=all')->assertOk()->assertJsonCount(2, 'data')->json('data');
    expect(collect($all)->firstWhere('id', $first->id)['read_at'])->not->toBeNull();

    $this->getJson('/api/v1/notifications?filter=nope')->assertUnprocessable();
});

it('marks all of the caller\'s notices as read, and nobody else\'s', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'otherTeacher' => $otherTeacher, 'student' => $student] = noticesScenario();
    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/students/{$student->id}/accommodations", noticeAccommodationPayload(['type' => 'Uno']))->assertCreated();
    $this->postJson("/api/v1/students/{$student->id}/accommodations", noticeAccommodationPayload(['type' => 'Dos']))->assertCreated();

    Sanctum::actingAs($teacher);
    $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 2);
    $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 0);
    $this->getJson('/api/v1/notifications?filter=all')->assertOk()->assertJsonCount(2, 'data');

    expect($otherTeacher->unreadNotifications()->count())->toBe(2);
});

it('requires authentication for the history and for marking everything read', function () {
    $this->getJson('/api/v1/notifications?filter=all')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
});

it('does not name the student when the recipient can no longer see them', function () {
    ['psycho' => $psycho, 'teacher' => $teacher, 'student' => $student, 'group' => $group] = noticesScenario();
    Sanctum::actingAs($psycho);
    $this->postJson("/api/v1/students/{$student->id}/accommodations", noticeAccommodationPayload())->assertCreated();

    // The teacher stops teaching the group.
    $group->teachers()->detach($teacher->id);

    Sanctum::actingAs($teacher);
    $this->getJson('/api/v1/notifications?filter=all')->assertOk()
        ->assertJsonPath('data.0.data.type', 'accommodation_confirmed')
        ->assertJsonPath('data.0.data.student_name', null);
});
