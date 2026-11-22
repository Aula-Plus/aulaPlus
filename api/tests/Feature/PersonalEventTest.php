<?php

use App\Models\Calendar;
use App\Models\CalendarEvent;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Notifications\CalendarEventInvitedNotification;
use App\Services\Calendar\EventParticipants;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * Each person's own calendar (screen 21, ClickUp 1dfd3mc56kp): every role
 * creates events, invites active colleagues of the school, the event is
 * stored once and appears once in each participant's calendar, and naming a
 * student never gives access to a file the invitee could not open.
 */
function personalFeed(string $from = '2026-12-01', string $to = '2026-12-31'): string
{
    return '/api/v1/calendar?'.http_build_query(['from' => $from, 'to' => $to, 'types' => ['meeting']]);
}

function personalPayload(array $overrides = []): array
{
    return [
        'title' => 'Coordinar salida didáctica',
        'date' => '2026-12-15',
        'time' => '10:30',
        'location' => 'Sala de profesores',
        'description' => 'Traer la lista de autorizaciones.',
        ...$overrides,
    ];
}

it('lets every role create an event of its own calendar', function (string $role) {
    $school = School::factory()->create();
    $user = User::factory()->forSchool($school)->{$role}()->create();
    Sanctum::actingAs($user);

    $item = $this->postJson('/api/v1/personal-events', personalPayload())->assertCreated()->json('data');

    expect($item)->toMatchArray([
        'type' => 'meeting',
        'date' => '2026-12-15',
        'time' => '10:30',
        'title' => 'Coordinar salida didáctica',
        'place' => 'Sala de profesores',
        'detail' => 'Traer la lista de autorizaciones.',
        'can_edit' => true,
    ])->and($item['shared'])->toMatchArray([
        'kind' => 'personal',
        'is_owner' => true,
        'can_leave' => false,
        'participants' => [['id' => $user->id, 'name' => $user->name]],
        'student' => null,
    ]);

    expect($this->getJson(personalFeed())->assertOk()->json('data'))->toHaveCount(1);
})->with(['teacher', 'psychopedagogue', 'director']);

it('stores a shared event once and shows it once in each participant calendar, with a notice', function () {
    Notification::fake();
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Laura Ferrando']);
    $teacher = User::factory()->forSchool($school)->teacher()->create(['name' => 'Mariana López']);
    $director = User::factory()->forSchool($school)->director()->create(['name' => 'Adriana Ruiz']);
    $outsider = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($owner);

    $id = $this->postJson('/api/v1/personal-events', personalPayload(['invitee_ids' => [$teacher->id, $director->id]]))
        ->assertCreated()->json('data.id');

    expect(CalendarEvent::query()->count())->toBe(1);
    Notification::assertSentTo([$teacher, $director], CalendarEventInvitedNotification::class, function ($notification, $channels, $notifiable) use ($id, $owner) {
        return $notification->toArray($notifiable) === [
            'type' => 'calendar_event_invited',
            'calendar_event_id' => $id,
            'event_type' => 'personal',
            'title' => 'Coordinar salida didáctica',
            'date' => '2026-12-15',
            'time' => '10:30',
            'invited_by_id' => $owner->id,
            'invited_by_name' => 'Laura Ferrando',
        ];
    });
    Notification::assertNotSentTo([$owner, $outsider], CalendarEventInvitedNotification::class);

    foreach ([$teacher, $director] as $invitee) {
        Sanctum::actingAs($invitee);
        $items = $this->getJson(personalFeed())->assertOk()->json('data');
        expect($items)->toHaveCount(1)
            ->and($items[0]['id'])->toBe($id)
            ->and($items[0]['can_edit'])->toBeFalse()
            ->and($items[0]['place'])->toBe('Sala de profesores')
            ->and($items[0]['shared']['is_owner'])->toBeFalse()
            ->and($items[0]['shared']['can_leave'])->toBeTrue()
            ->and($items[0]['shared']['owner'])->toBe(['id' => $owner->id, 'name' => 'Laura Ferrando'])
            // Owner first, then the others by name.
            ->and(array_column($items[0]['shared']['participants'], 'name'))->toBe(['Laura Ferrando', 'Adriana Ruiz', 'Mariana López']);
    }

    // Not invited: it does not exist for them (red line), not even for Direction-wide roles.
    Sanctum::actingAs($outsider);
    expect($this->getJson(personalFeed())->assertOk()->json('data'))->toBe([]);
    $this->patchJson("/api/v1/personal-events/{$id}", ['title' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/personal-events/{$id}")->assertNotFound();
    $this->deleteJson("/api/v1/calendar-events/{$id}/participation")->assertNotFound();
});

it('shows the linked student name to every participant but the file only to whoever could already open it', function () {
    $school = School::factory()->create();
    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create();
    $ownTeacher = User::factory()->forSchool($school)->teacher()->create();
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $ownTeacher);
    $student = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Lucía Pereyra']);
    $group->students()->attach($student->id, ['school_year' => (int) now()->year]);
    Sanctum::actingAs($psycho);

    $this->postJson('/api/v1/personal-events', personalPayload([
        'student_id' => $student->id,
        'invitee_ids' => [$ownTeacher->id, $otherTeacher->id],
    ]))->assertCreated()->assertJsonPath('data.shared.student', ['id' => $student->id, 'name' => 'Lucía Pereyra', 'can_view' => true]);

    Sanctum::actingAs($ownTeacher);
    expect($this->getJson(personalFeed())->json('data.0.shared.student'))
        ->toBe(['id' => $student->id, 'name' => 'Lucía Pereyra', 'can_view' => true]);

    Sanctum::actingAs($otherTeacher);
    expect($this->getJson(personalFeed())->json('data.0.shared.student'))
        ->toBe(['id' => $student->id, 'name' => 'Lucía Pereyra', 'can_view' => false]);
    // The invitation does not widen access to the file.
    $this->getJson("/api/students/{$student->id}")->assertForbidden();
});

it('only lets the creator name a student they already see, of their own school', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $notTheirs = Student::factory()->create(['school_id' => $school->id]);
    $foreign = Student::factory()->create(['school_id' => School::factory()->create()->id]);
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/personal-events', personalPayload(['student_id' => $notTheirs->id]))
        ->assertUnprocessable()->assertJsonValidationErrorFor('student_id');
    $this->postJson('/api/v1/personal-events', personalPayload(['student_id' => $foreign->id]))
        ->assertUnprocessable()->assertJsonValidationErrorFor('student_id');
    expect(CalendarEvent::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('only invites active users of the same school, never oneself', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $disabled = User::factory()->forSchool($school)->teacher()->create(['disabled_at' => now()]);
    $pending = User::factory()->forSchool($school)->teacher()->create(['password' => null]);
    $foreign = User::factory()->forSchool(School::factory()->create())->teacher()->create();
    Sanctum::actingAs($owner);

    foreach ([$disabled, $pending, $foreign, $owner] as $invalid) {
        $this->postJson('/api/v1/personal-events', personalPayload(['invitee_ids' => [$invalid->id]]))
            ->assertUnprocessable()->assertJsonValidationErrorFor('invitee_ids.0');
    }
    $this->postJson('/api/v1/personal-events', [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'date']);
    expect(CalendarEvent::query()->withoutGlobalScopes()->count())->toBe(0);

    $options = $this->getJson('/api/v1/personal-events/options')->assertOk()->json('data');
    expect($options['users'])->toBe([]);
});

it('offers active colleagues and the students the caller sees as options', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $colleague = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Laura Ferrando']);
    User::factory()->forSchool($school)->teacher()->create(['disabled_at' => now()]);
    User::factory()->forSchool(School::factory()->create())->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $mine = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Tomás Silva']);
    $group->students()->attach($mine->id, ['school_year' => (int) now()->year]);
    Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson('/api/v1/personal-events/options')->assertOk()->assertExactJson(['data' => [
        'users' => [['id' => $colleague->id, 'name' => 'Laura Ferrando', 'role' => 'psychopedagogue']],
        'students' => [['id' => $mine->id, 'name' => 'Tomás Silva']],
    ]]);
});

it('lets only the creator edit or delete; an invitee only leaves it, without removing it for the others', function () {
    Notification::fake();
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $a = User::factory()->forSchool($school)->teacher()->create();
    $b = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($owner);
    $id = $this->postJson('/api/v1/personal-events', personalPayload(['invitee_ids' => [$a->id, $b->id]]))->json('data.id');

    // An invitee (even Direction) cannot edit or delete it.
    Sanctum::actingAs($b);
    $this->patchJson("/api/v1/personal-events/{$id}", ['title' => 'Otro'])->assertForbidden();
    $this->deleteJson("/api/v1/personal-events/{$id}")->assertForbidden();
    // Nor through the institutional endpoints.
    $this->patchJson("/api/v1/calendar-events/{$id}", ['title' => 'Otro'])->assertForbidden();
    $this->deleteJson("/api/v1/calendar-events/{$id}")->assertForbidden();

    $this->deleteJson("/api/v1/calendar-events/{$id}/participation")->assertNoContent();
    expect($this->getJson(personalFeed())->json('data'))->toBe([]);

    Sanctum::actingAs($a);
    expect($this->getJson(personalFeed())->json('data.0.shared.participants'))->toHaveCount(2);

    // The creator cannot "leave" its own event: it deletes it.
    Sanctum::actingAs($owner);
    $this->deleteJson("/api/v1/calendar-events/{$id}/participation")->assertForbidden();
    $this->patchJson("/api/v1/personal-events/{$id}", ['title' => 'Salida didáctica', 'time' => null])
        ->assertOk()
        ->assertJsonPath('data.title', 'Salida didáctica')
        ->assertJsonPath('data.time', null)
        ->assertJsonPath('data.place', 'Sala de profesores');
    $this->deleteJson("/api/v1/personal-events/{$id}")->assertNoContent();

    Sanctum::actingAs($a);
    expect($this->getJson(personalFeed())->json('data'))->toBe([]);
});

it('notifies only the people newly invited when the creator edits the list', function () {
    Notification::fake();
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $kept = User::factory()->forSchool($school)->teacher()->create();
    $dropped = User::factory()->forSchool($school)->teacher()->create();
    $added = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($owner);
    $id = $this->postJson('/api/v1/personal-events', personalPayload(['invitee_ids' => [$kept->id, $dropped->id]]))->json('data.id');
    Notification::fake();

    $participants = $this->patchJson("/api/v1/personal-events/{$id}", ['invitee_ids' => [$kept->id, $added->id]])
        ->assertOk()->json('data.shared.participants');

    expect(array_column($participants, 'id'))->toEqualCanonicalizing([$owner->id, $kept->id, $added->id]);
    Notification::assertSentTo($added, CalendarEventInvitedNotification::class);
    Notification::assertNotSentTo([$kept, $dropped, $owner], CalendarEventInvitedNotification::class);

    Sanctum::actingAs($dropped);
    expect($this->getJson(personalFeed())->json('data'))->toBe([]);
});

it('isolates schools: another school cannot see, edit or leave an event', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($owner);
    $id = $this->postJson('/api/v1/personal-events', personalPayload())->json('data.id');

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->director()->create());
    expect($this->getJson(personalFeed())->json('data'))->toBe([]);
    $this->patchJson("/api/v1/personal-events/{$id}", ['title' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/personal-events/{$id}")->assertNotFound();
    $this->deleteJson("/api/v1/calendar-events/{$id}/participation")->assertNotFound();
});

it('does not edit institutional events through the personal endpoint', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $event = CalendarEvent::factory()->create(['school_id' => $school->id, 'start_at' => '2026-12-15 12:00:00']);
    Sanctum::actingAs($director);

    $this->patchJson("/api/v1/personal-events/{$event->id}", ['title' => 'x'])->assertForbidden();
    $this->deleteJson("/api/v1/personal-events/{$event->id}")->assertForbidden();
    $this->deleteJson("/api/v1/calendar-events/{$event->id}/participation")->assertForbidden();
});

it('keeps the invitation mechanism reusable for meetings (screen 10)', function () {
    Notification::fake();
    $school = School::factory()->create();
    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $meeting = CalendarEvent::factory()->meeting()->create([
        'school_id' => $school->id, 'start_at' => '2026-12-15 12:00:00', 'created_by_id' => $psycho->id,
    ]);
    $participants = app(EventParticipants::class);
    $participants->addOwner($meeting, $psycho);
    $participants->invite($meeting, [$teacher, $teacher], $psycho);

    Notification::assertSentToTimes($teacher, CalendarEventInvitedNotification::class, 1);
    expect(Calendar::query()->withoutGlobalScopes()->count())->toBe(2);

    Sanctum::actingAs($teacher);
    $item = $this->getJson(personalFeed())->assertOk()->json('data.0');
    expect($item['shared']['kind'])->toBe('meeting')->and($item['can_edit'])->toBeFalse();

    expect(fn () => $participants->invite($meeting, [User::factory()->forSchool(School::factory()->create())->create()]))
        ->toThrow(InvalidArgumentException::class);
});

it('lists the invitation notice on the notifications screen', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $invitee = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/personal-events', personalPayload(['invitee_ids' => [$invitee->id]]))->assertCreated();

    Sanctum::actingAs($invitee);
    $this->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonPath('data.0.data.type', 'calendar_event_invited')
        ->assertJsonPath('data.0.data.title', 'Coordinar salida didáctica');
    $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);
});
