<?php

use App\Enums\AIProposalType;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\School;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Time and room of a class (ClickUp 1dfd3mc4bgh): data of the class itself,
 * optional, editable by its teacher at any moment, and pre-filled — never
 * generated — from the teacher's own «Mis horarios».
 */

/**
 * @return array{0: User, 1: Group, 2: Subject}
 */
function scheduleTeacher(School $school): array
{
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => '2°EMS-A']);
    $subject = leadGroup($group, $teacher);

    return [$teacher, $group, $subject];
}

function slotFor(User $teacher, Group $group, Subject $subject, int $weekday, string $time, ?string $room): TimetableSlot
{
    return TimetableSlot::factory()->create([
        'school_id' => $group->school_id, 'teacher_id' => $teacher->id, 'group_id' => $group->id,
        'subject_id' => $subject->id, 'weekday' => $weekday, 'start_time' => $time, 'room' => $room,
    ]);
}

it('lets the class\'s teacher set, change and clear its time and room', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = scheduleTeacher($school);
    $session = ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $subject->id, 'date' => '2026-12-17']);
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/class-sessions/{$session->id}", ['start_time' => '08:30', 'room' => 'Aula 4'])
        ->assertOk()
        ->assertJsonPath('data.start_time', '08:30')
        ->assertJsonPath('data.room', 'Aula 4');

    $this->patchJson("/api/v1/class-sessions/{$session->id}", ['start_time' => null])
        ->assertOk()
        ->assertJsonPath('data.start_time', null)
        ->assertJsonPath('data.room', 'Aula 4');
});

it('validates the time and room of a class', function () {
    $school = School::factory()->create();
    [$teacher, $group] = scheduleTeacher($school);
    $session = ClassSession::factory()->create(['group_id' => $group->id]);
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/class-sessions/{$session->id}", ['start_time' => '8.30', 'room' => str_repeat('a', 61)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['start_time', 'room']);
});

it('forbids anyone but the class\'s teacher from editing its time and room', function () {
    $school = School::factory()->create();
    [, $group, $subject] = scheduleTeacher($school);
    $session = ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $subject->id]);
    // Another teacher of the same group, other subject.
    $colleague = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $colleague);

    foreach ([$colleague, User::factory()->forSchool($school)->director()->create(), User::factory()->forSchool($school)->psychopedagogue()->create()] as $user) {
        Sanctum::actingAs($user);
        $this->patchJson("/api/v1/class-sessions/{$session->id}", ['room' => 'Aula 9'])->assertForbidden();
    }
    expect($session->fresh()->room)->not->toBe('Aula 9');
});

it('returns 404 for another school\'s class (tenant isolation)', function () {
    [, $groupB] = scheduleTeacher(School::factory()->create());
    $sessionB = ClassSession::factory()->create(['group_id' => $groupB->id]);
    [$teacherA] = scheduleTeacher(School::factory()->create());
    Sanctum::actingAs($teacherA);

    $this->patchJson("/api/v1/class-sessions/{$sessionB->id}", ['room' => 'X'])->assertNotFound();
});

it('suggests the slot of the class\'s weekday from the teacher\'s own timetable', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = scheduleTeacher($school);
    // Lunes 10:15 y jueves 08:30, Aula 4.
    slotFor($teacher, $group, $subject, 1, '10:15', 'Aula 4');
    slotFor($teacher, $group, $subject, 4, '08:30', 'Aula 4');
    Sanctum::actingAs($teacher);

    // 2026-12-17 is a Thursday.
    $this->getJson("/api/v1/class-sessions/suggested-slot?group_id={$group->id}&subject_id={$subject->id}&date=2026-12-17")
        ->assertOk()
        ->assertJsonPath('data.start_time', '08:30')
        ->assertJsonPath('data.room', 'Aula 4');

    // A Wednesday: nothing to propose.
    $this->getJson("/api/v1/class-sessions/suggested-slot?group_id={$group->id}&subject_id={$subject->id}&date=2026-12-16")
        ->assertOk()
        ->assertExactJson(['data' => null]);
});

it('proposes the free slot when the weekday has two, else the first of the day', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = scheduleTeacher($school);
    slotFor($teacher, $group, $subject, 2, '11:30', 'Aula 7');
    slotFor($teacher, $group, $subject, 2, '08:30', 'Aula 12');
    Sanctum::actingAs($teacher);
    $url = "/api/v1/class-sessions/suggested-slot?group_id={$group->id}&subject_id={$subject->id}&date=2026-12-15";

    // Both free: the first of the day.
    $this->getJson($url)->assertJsonPath('data.start_time', '08:30');

    // 08:30 already has a class that day: the free one.
    $first = ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $subject->id, 'date' => '2026-12-15', 'start_time' => '08:30']);
    $this->getJson($url)->assertJsonPath('data.start_time', '11:30')->assertJsonPath('data.room', 'Aula 7');

    // Editing that same class, its own slot is not taken.
    $this->getJson($url."&class_session_id={$first->id}")->assertJsonPath('data.start_time', '08:30');

    // Both taken: the first of the day.
    ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $subject->id, 'date' => '2026-12-15', 'start_time' => '11:30']);
    $this->getJson($url)->assertJsonPath('data.start_time', '08:30');
});

it('never suggests from another teacher\'s timetable', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = scheduleTeacher($school);
    $colleague = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $colleague, $subject);
    slotFor($colleague, $group, $subject, 4, '08:30', 'Aula 4');
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/class-sessions/suggested-slot?group_id={$group->id}&subject_id={$subject->id}&date=2026-12-17")
        ->assertOk()->assertExactJson(['data' => null]);
});

it('refuses a suggestion for a group-subject the teacher does not teach, or for non-teachers', function () {
    $school = School::factory()->create();
    [$teacher, $group] = scheduleTeacher($school);
    $otherSubject = Subject::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/class-sessions/suggested-slot?group_id={$group->id}&subject_id={$otherSubject->id}&date=2026-12-17")
        ->assertUnprocessable()->assertJsonValidationErrorFor('subject_id');

    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $this->getJson("/api/v1/class-sessions/suggested-slot?group_id={$group->id}&subject_id={$otherSubject->id}&date=2026-12-17")
        ->assertForbidden();
});

it('does not touch existing classes when the timetable changes', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = scheduleTeacher($school);
    $slot = slotFor($teacher, $group, $subject, 4, '08:30', 'Aula 4');
    $session = ClassSession::factory()->create(['group_id' => $group->id, 'subject_id' => $subject->id, 'date' => '2026-12-17', 'start_time' => '08:30', 'room' => 'Aula 4']);
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/timetable-slots/{$slot->id}", ['start_time' => '09:00', 'room' => 'Aula 5'])->assertOk();
    $this->deleteJson("/api/v1/timetable-slots/{$slot->id}")->assertNoContent();

    expect($session->fresh()->start_time)->toBe('08:30')
        ->and($session->fresh()->room)->toBe('Aula 4')
        ->and(ClassSession::count())->toBe(1);
});

it('pre-fills time and room from «Mis horarios» when a generated class is applied', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = scheduleTeacher($school);
    slotFor($teacher, $group, $subject, 4, '08:30', 'Aula 4');
    $proposal = AIProposal::factory()->completed([
        'title' => 'Repaso de ecuaciones', 'objective' => 'x', 'description' => 'y',
        'duration_minutes' => 45, 'suggested_date' => '2026-12-17', 'rationale' => 'z',
    ])->create([
        'group_id' => $group->id, 'requested_by_id' => $teacher->id,
        'type' => AIProposalType::ClassSession, 'input_parameters' => ['focus' => 'ecuaciones'],
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply")->assertOk();

    $session = ClassSession::sole();
    // The teacher teaches a single subject in the group: it is the class's.
    expect($session->subject_id)->toBe($subject->id)
        ->and($session->start_time)->toBe('08:30')
        ->and($session->room)->toBe('Aula 4');
});

it('gives two generated classes on the same day the two free slots', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = scheduleTeacher($school);
    slotFor($teacher, $group, $subject, 2, '08:30', 'Aula 12');
    slotFor($teacher, $group, $subject, 2, '11:30', 'Aula 7');
    $plan = AnnualPlan::factory()->create(['school_id' => $school->id, 'group_id' => $group->id, 'teacher_id' => $teacher->id, 'subject_id' => $subject->id]);
    $proposal = AIProposal::factory()->completed([
        'name' => 'Unidad 6', 'suggested_start_date' => '2026-12-15', 'suggested_end_date' => '2026-12-22',
        'sessions' => [['title' => 'A', 'duration_minutes' => 45], ['title' => 'B', 'duration_minutes' => 45]],
    ])->create([
        'group_id' => $group->id, 'requested_by_id' => $teacher->id,
        'type' => AIProposalType::Unit, 'input_parameters' => ['annual_plan_id' => $plan->id, 'focus' => 'x'],
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply")->assertOk();

    expect(ClassSession::orderBy('id')->get()->map->only(['subject_id', 'start_time', 'room'])->all())->toBe([
        ['subject_id' => $subject->id, 'start_time' => '08:30', 'room' => 'Aula 12'],
        ['subject_id' => $subject->id, 'start_time' => '11:30', 'room' => 'Aula 7'],
    ]);
});

it('leaves a generated class without time when the timetable has nothing that day', function () {
    $school = School::factory()->create();
    [$teacher, $group] = scheduleTeacher($school);
    $proposal = AIProposal::factory()->completed([
        'title' => 'Clase', 'suggested_date' => '2026-12-16', 'rationale' => 'z',
    ])->create([
        'group_id' => $group->id, 'requested_by_id' => $teacher->id,
        'type' => AIProposalType::ClassSession, 'input_parameters' => ['focus' => 'x'],
    ]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/ai-proposals/{$proposal->id}/apply")->assertOk();

    expect(ClassSession::sole()->start_time)->toBeNull();
});
