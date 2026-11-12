<?php

use App\Models\Assessment;
use App\Models\Calendar;
use App\Models\CalendarEvent;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\SchoolTime;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Calendar feed (screen 21, ClickUp 1dfd3mc4bgh): each person sees their own
 * dates, never more than they already see elsewhere.
 */

/**
 * @return array{0: User, 1: Group, 2: Subject}
 */
function calendarTeacher(School $school, string $groupName = '5°A', string $subjectName = 'Matemática'): array
{
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => $groupName]);
    $subject = Subject::factory()->create(['school_id' => $school->id, 'name' => $subjectName]);
    leadGroup($group, $teacher, $subject);

    return [$teacher, $group, $subject];
}

function feed(string $from = '2026-12-01', string $to = '2026-12-31', array $types = []): string
{
    $query = http_build_query(array_filter(['from' => $from, 'to' => $to, 'types' => $types ?: null]));

    return '/api/v1/calendar?'.$query;
}

it('shows a teacher their classes of the day with time, group, subject and room (Inicio, 15/12)', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = calendarTeacher($school);

    foreach ([['10:15', 'Aula 4'], ['08:30', 'Aula 12'], [null, null]] as [$time, $room]) {
        ClassSession::factory()->create([
            'group_id' => $group->id, 'subject_id' => $subject->id,
            'date' => '2026-12-15', 'start_time' => $time, 'room' => $room, 'duration_minutes' => 45,
        ]);
    }
    Sanctum::actingAs($teacher);

    $items = $this->getJson(feed('2026-12-15', '2026-12-15'))->assertOk()->json('data');

    expect($items)->toHaveCount(3)
        // Without a time first, then by time.
        ->and(array_column($items, 'time'))->toBe([null, '08:30', '10:15'])
        ->and($items[1])->toMatchArray([
            'type' => 'class',
            'date' => '2026-12-15',
            'end_time' => '09:15',
            'title' => 'Matemática',
            'place' => 'Aula 12',
            'group' => ['id' => $group->id, 'name' => '5°A'],
            'can_edit' => true,
        ]);
});

it('never shows a teacher the classes of a group or subject they do not teach', function () {
    $school = School::factory()->create();
    [$teacher, $group] = calendarTeacher($school);
    // Same group, another teacher's subject.
    $otherSubject = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Inglés']);
    leadGroup($group, User::factory()->forSchool($school)->teacher()->create(), $otherSubject);
    ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $otherSubject->id, 'date' => '2026-12-15']);
    // A group they don't teach at all.
    ClassSession::factory()->create(['group_id' => Group::factory()->create(['school_id' => $school->id])->id, 'date' => '2026-12-15']);
    // A class of their group without a subject: visible (previous rule).
    $own = ClassSession::factory()->create(['group_id' => $group->id, 'date' => '2026-12-15']);
    Sanctum::actingAs($teacher);

    $items = $this->getJson(feed())->assertOk()->json('data');

    expect(array_column($items, 'id'))->toBe([$own->id]);
});

it('shows classes only to teachers, not to psychopedagogy or direction', function () {
    $school = School::factory()->create();
    [, $group, $subject] = calendarTeacher($school);
    ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $subject->id, 'date' => '2026-12-15']);

    foreach (['director', 'psychopedagogue'] as $role) {
        Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());
        $types = array_column($this->getJson(feed())->assertOk()->json('data'), 'type');
        expect($types)->not->toContain('class');
    }
});

it('shows a teacher their own assessments and school-wide roles all of them, without scores', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = calendarTeacher($school);
    $mine = Assessment::factory()->create([
        'group_id' => $group->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        'administered_at' => '2026-12-10', 'purpose' => 'Escrito de integración', 'type' => 'written',
    ]);
    [$other, $otherGroup] = calendarTeacher($school, '9°CB-A', 'Historia');
    $theirs = Assessment::factory()->create(['group_id' => $otherGroup->id, 'teacher_id' => $other->id, 'administered_at' => '2026-12-11']);

    Sanctum::actingAs($teacher);
    $items = $this->getJson(feed(types: ['assessment']))->assertOk()->json('data');
    expect(array_column($items, 'id'))->toBe([$mine->id])
        ->and($items[0])->toMatchArray(['type' => 'assessment', 'title' => 'Escrito de integración', 'assessment_type' => 'written'])
        ->and($items[0])->not->toHaveKey('score');

    foreach (['director', 'psychopedagogue'] as $role) {
        Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());
        $ids = array_column($this->getJson(feed(types: ['assessment']))->assertOk()->json('data'), 'id');
        expect($ids)->toBe([$mine->id, $theirs->id]);
    }
});

it('lists the caller\'s own pending follow-ups as tasks, without the student name', function () {
    $school = School::factory()->create();
    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Lucía Pereyra']);
    $mine = ScheduledFollowUp::factory()->create([
        'school_id' => $school->id, 'student_id' => $student->id, 'created_by_id' => $psycho->id,
        'title' => 'Revisar ajustes', 'due_date' => '2026-12-05',
    ]);
    ScheduledFollowUp::factory()->resolved()->create(['school_id' => $school->id, 'student_id' => $student->id, 'created_by_id' => $psycho->id, 'due_date' => '2026-12-06']);
    // Someone else's follow-up.
    $other = User::factory()->forSchool($school)->psychopedagogue()->create();
    ScheduledFollowUp::factory()->create(['school_id' => $school->id, 'student_id' => $student->id, 'created_by_id' => $other->id, 'due_date' => '2026-12-07']);
    Sanctum::actingAs($psycho);

    $response = $this->getJson(feed())->assertOk();
    $tasks = collect($response->json('data'))->where('type', 'task')->values();

    expect($tasks->pluck('id')->all())->toBe([$mine->id])
        ->and($tasks[0]['title'])->toBe('Revisar ajustes')
        ->and($response->getContent())->not->toContain('Lucía')->not->toContain('Pereyra');
});

it('shows institutional events to everyone in the school, in school-local time', function () {
    $school = School::factory()->create();
    $event = CalendarEvent::factory()->create([
        'school_id' => $school->id, 'title' => 'Jornada de planificación', 'location' => 'Salón de actos',
        // 08:00 in Montevideo (UTC-3).
        'start_at' => '2026-12-15 11:00:00', 'end_at' => null, 'all_day' => false,
    ]);
    $allDay = CalendarEvent::factory()->create([
        'school_id' => $school->id, 'title' => 'Cierre del tercer período',
        'start_at' => SchoolTime::startOfDay('2026-12-15'), 'end_at' => null, 'all_day' => true,
    ]);

    foreach (['teacher', 'director', 'psychopedagogue'] as $role) {
        Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());
        $items = $this->getJson(feed('2026-12-15', '2026-12-15'))->assertOk()->json('data');
        expect(array_column($items, 'id'))->toBe([$allDay->id, $event->id])
            ->and($items[1])->toMatchArray(['type' => 'institutional', 'date' => '2026-12-15', 'time' => '08:00', 'place' => 'Salón de actos'])
            ->and($items[0]['time'])->toBeNull()
            ->and($items[1]['can_edit'])->toBe($role === 'director');
    }
});

it('never shows a meeting to someone not invited, not even to direction (red line)', function () {
    $school = School::factory()->create();
    $invited = User::factory()->forSchool($school)->psychopedagogue()->create();
    $meeting = CalendarEvent::factory()->meeting()->create(['school_id' => $school->id, 'start_at' => '2026-12-15 12:00:00']);
    Calendar::factory()->create(['school_id' => $school->id, 'user_id' => $invited->id])->events()->attach($meeting);

    foreach (['teacher', 'director'] as $role) {
        $user = User::factory()->forSchool($school)->{$role}()->create();
        Sanctum::actingAs($user);
        expect($this->getJson(feed())->assertOk()->json('data'))->toBe([])
            ->and($user->can('view', $meeting))->toBeFalse();
    }
    expect($invited->can('view', $meeting))->toBeTrue();
});

it('keeps the seven types valid, the three without a source returning nothing', function () {
    $school = School::factory()->create();
    CalendarEvent::factory()->create(['school_id' => $school->id, 'start_at' => '2026-12-15 12:00:00']);
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->getJson(feed(types: ['meeting', 'period_closing', 'project_stage']))
        ->assertOk()->assertExactJson(['data' => []]);
    $this->getJson(feed(types: ['institutional']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(feed(types: ['holiday']))->assertUnprocessable()->assertJsonValidationErrorFor('types.0');
});

it('validates the window', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());

    $this->getJson('/api/v1/calendar')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    $this->getJson(feed('2026-12-10', '2026-12-01'))->assertUnprocessable()->assertJsonValidationErrorFor('to');
    $this->getJson(feed('2026-01-01', '2026-06-01'))->assertUnprocessable()->assertJsonValidationErrorFor('to');
});

it('requires authentication', function () {
    $this->getJson(feed())->assertUnauthorized();
});

it('never leaks another school\'s dates into the feed', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    [$teacherB, $groupB, $subjectB] = calendarTeacher($schoolB);
    ClassSession::factory()->create(['group_id' => $groupB->id, 'subject_id' => $subjectB->id, 'date' => '2026-12-15']);
    Assessment::factory()->create(['group_id' => $groupB->id, 'teacher_id' => $teacherB->id, 'administered_at' => '2026-12-15']);
    CalendarEvent::factory()->create(['school_id' => $schoolB->id, 'start_at' => '2026-12-15 12:00:00']);

    Sanctum::actingAs(User::factory()->forSchool($schoolA)->director()->create());

    $this->getJson(feed())->assertOk()->assertExactJson(['data' => []]);
});

it('marks every class in the feed as editable exactly when the policy allows it, with or without subject', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = calendarTeacher($school);
    $coTeacher = User::factory()->forSchool($school)->teacher()->create();
    $otherSubject = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Inglés']);
    leadGroup($group, $coTeacher, $otherSubject);
    $withSubject = ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $subject->id, 'date' => '2026-12-15']);
    $coTeachers = ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $otherSubject->id, 'date' => '2026-12-15']);
    $withoutSubject = ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => null, 'date' => '2026-12-15']);

    foreach ([$teacher, $coTeacher] as $user) {
        Sanctum::actingAs($user);
        $items = collect($this->getJson(feed())->assertOk()->json('data'))->where('type', 'class');

        foreach ([$withSubject, $coTeachers, $withoutSubject] as $session) {
            $item = $items->firstWhere('id', $session->id);
            $allowed = $user->can('update', $session->fresh());

            // In the feed ⇔ the policy lets them edit it; and then can_edit.
            expect($item !== null)->toBe($allowed);
            if ($item !== null) {
                expect($item['can_edit'])->toBeTrue();
            }
        }
    }

    // The class without a subject: any teacher of the group.
    Sanctum::actingAs($coTeacher);
    expect(collect($this->getJson(feed())->json('data'))->pluck('id'))->toContain($withoutSubject->id)
        ->not->toContain($withSubject->id);
});

it('builds the teacher feed with a constant number of queries, however many classes', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = calendarTeacher($school);
    Sanctum::actingAs($teacher);
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(feed())->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $subject->id, 'date' => '2026-12-01']);
    $count(); // warm-up: per-request caches (roles, permissions) fill on the first call
    $one = $count();

    ClassSession::factory()->count(19)->sequence(fn ($s) => ['date' => '2026-12-'.str_pad((string) ($s->index + 2), 2, '0', STR_PAD_LEFT)])
        ->create(['group_id' => $group->id, 'subject_id' => $subject->id]);
    $twenty = $count();

    expect($twenty)->toBe($one);
});

it('puts an institutional event on its local day in the group timeline marks', function () {
    $school = School::factory()->create();
    [$teacher, $group] = calendarTeacher($school);
    // 22:00 in Montevideo on 15/12 is 01:00 UTC on 16/12.
    CalendarEvent::factory()->create([
        'school_id' => $school->id, 'type' => 'institutional', 'title' => 'Acto de fin de año',
        'start_at' => SchoolTime::startOfDay('2026-12-15')->addHours(22),
    ]);
    Sanctum::actingAs($teacher);

    $marks = collect($this->getJson("/api/v1/groups/{$group->id}/performance-timeline?from=2026-12-01&to=2026-12-15")
        ->assertOk()->json('marks'))->where('type', 'calendar_event');

    expect($marks)->toHaveCount(1)
        ->and($marks->first()['date'])->toBe('2026-12-15');
});
