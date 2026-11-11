<?php

use App\Models\Calendar;
use App\Models\CalendarEvent;
use App\Models\School;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Institutional events (screen 21, P21-DEC-05): Direction creates, edits and
 * removes them; everyone in the school sees them.
 */
it('lets direction create an institutional event with an optional time', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $this->postJson('/api/v1/calendar-events', [
        'title' => 'Jornada de planificación institucional',
        'date' => '2026-12-15',
        'time' => '08:00',
        'location' => 'Salón de actos',
    ])->assertCreated()
        ->assertJsonPath('data.type', 'institutional')
        ->assertJsonPath('data.date', '2026-12-15')
        ->assertJsonPath('data.time', '08:00')
        ->assertJsonPath('data.location', 'Salón de actos');

    $event = CalendarEvent::sole();
    expect($event->school_id)->toBe($school->id)
        ->and($event->created_by_id)->toBe($director->id)
        ->and($event->all_day)->toBeFalse()
        // Stored in UTC: 08:00 in Montevideo.
        ->and($event->start_at->format('Y-m-d H:i'))->toBe('2026-12-15 11:00');

    $this->postJson('/api/v1/calendar-events', ['title' => 'Cierre del tercer período', 'date' => '2026-12-11'])
        ->assertCreated()
        ->assertJsonPath('data.time', null)
        ->assertJsonPath('data.date', '2026-12-11');
});

it('forbids teachers and psychopedagogy from creating institutional events', function () {
    $school = School::factory()->create();

    foreach (['teacher', 'psychopedagogue'] as $role) {
        Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());
        $this->postJson('/api/v1/calendar-events', ['title' => 'X', 'date' => '2026-12-15'])->assertForbidden();
    }
    expect(CalendarEvent::count())->toBe(0);
});

it('validates an institutional event', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->postJson('/api/v1/calendar-events', ['time' => '25:00', 'date' => '15/12/2026'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'date', 'time']);
});

it('lets direction edit and remove an institutional event, keeping what was not sent', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $id = $this->postJson('/api/v1/calendar-events', [
        'title' => 'Acto', 'date' => '2026-12-15', 'time' => '10:00', 'location' => 'Patio',
    ])->json('data.id');

    $this->patchJson("/api/v1/calendar-events/{$id}", ['time' => null, 'title' => 'Acto de fin de año'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Acto de fin de año')
        ->assertJsonPath('data.date', '2026-12-15')
        ->assertJsonPath('data.time', null)
        ->assertJsonPath('data.location', 'Patio');

    $this->deleteJson("/api/v1/calendar-events/{$id}")->assertNoContent();
    expect(CalendarEvent::count())->toBe(0);
});

it('forbids non-directors from editing or removing an institutional event', function () {
    $school = School::factory()->create();
    $event = CalendarEvent::factory()->create(['school_id' => $school->id]);

    foreach (['teacher', 'psychopedagogue'] as $role) {
        Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());
        $this->patchJson("/api/v1/calendar-events/{$event->id}", ['title' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/calendar-events/{$event->id}")->assertForbidden();
    }
    expect($event->fresh())->not->toBeNull();
});

it('returns 404 for another school\'s event (tenant isolation)', function () {
    $eventB = CalendarEvent::factory()->create(['school_id' => School::factory()->create()->id]);
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->director()->create());

    $this->patchJson("/api/v1/calendar-events/{$eventB->id}", ['title' => 'X'])->assertNotFound();
    $this->deleteJson("/api/v1/calendar-events/{$eventB->id}")->assertNotFound();
    expect($eventB->fresh())->not->toBeNull();
});

it('returns 404 for a meeting the director is not invited to, and never edits meetings here', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $meeting = CalendarEvent::factory()->meeting()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $this->patchJson("/api/v1/calendar-events/{$meeting->id}", ['title' => 'X'])->assertNotFound();
    $this->deleteJson("/api/v1/calendar-events/{$meeting->id}")->assertNotFound();

    // Even when invited, this endpoint only handles institutional events.
    Calendar::factory()->create(['school_id' => $school->id, 'user_id' => $director->id])->events()->attach($meeting);
    $this->patchJson("/api/v1/calendar-events/{$meeting->id}", ['title' => 'X'])->assertForbidden();
    $this->deleteJson("/api/v1/calendar-events/{$meeting->id}")->assertForbidden();
});
