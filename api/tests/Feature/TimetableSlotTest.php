<?php

use App\Models\ClassSession;
use App\Models\Group;
use App\Models\School;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * «Mis horarios» (ClickUp 1dfd3mc4bgh): private to its teacher, a default
 * value only — it never creates a class.
 */
it('lets a teacher build their own timetable for the group-subjects they teach', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => '9°CB-A']);
    $subject = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']);
    leadGroup($group, $teacher, $subject);
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/timetable-slots', [
        'group_id' => $group->id, 'subject_id' => $subject->id, 'weekday' => 2, 'start_time' => '08:30', 'room' => 'Aula 12',
    ])->assertCreated()
        ->assertJsonPath('data.weekday', 2)
        ->assertJsonPath('data.start_time', '08:30')
        ->assertJsonPath('data.group.name', '9°CB-A')
        ->assertJsonPath('data.subject.name', 'Matemática');

    $this->getJson('/api/v1/timetable-slots')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.assignments.0.group.name', '9°CB-A')
        ->assertJsonPath('meta.assignments.0.subject.name', 'Matemática');

    // A timetable never creates a class (P21-DEC-02).
    expect(ClassSession::count())->toBe(0)
        ->and(TimetableSlot::sole()->teacher_id)->toBe($teacher->id);
});

it('rejects a slot for a group-subject the teacher does not teach, a duplicate, and bad values', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $subject = leadGroup($group, $teacher);
    $other = Subject::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/timetable-slots', ['group_id' => $group->id, 'subject_id' => $other->id, 'weekday' => 1, 'start_time' => '08:00'])
        ->assertUnprocessable()->assertJsonValidationErrorFor('subject_id');

    $this->postJson('/api/v1/timetable-slots', ['group_id' => $group->id, 'subject_id' => $subject->id, 'weekday' => 8, 'start_time' => '8h'])
        ->assertUnprocessable()->assertJsonValidationErrors(['weekday', 'start_time']);

    $payload = ['group_id' => $group->id, 'subject_id' => $subject->id, 'weekday' => 1, 'start_time' => '08:00'];
    $this->postJson('/api/v1/timetable-slots', $payload)->assertCreated();
    $this->postJson('/api/v1/timetable-slots', $payload)->assertUnprocessable()->assertJsonValidationErrorFor('start_time');
});

it('keeps the timetable private: no other teacher, psychopedagogy or direction can read or change it', function () {
    $school = School::factory()->create();
    $owner = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $subject = leadGroup($group, $owner);
    $slot = TimetableSlot::factory()->create([
        'school_id' => $school->id, 'teacher_id' => $owner->id, 'group_id' => $group->id, 'subject_id' => $subject->id,
    ]);

    $colleague = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $colleague, $subject);
    Sanctum::actingAs($colleague);
    $this->getJson('/api/v1/timetable-slots')->assertOk()->assertJsonCount(0, 'data');
    $this->patchJson("/api/v1/timetable-slots/{$slot->id}", ['room' => 'X'])->assertForbidden();
    $this->deleteJson("/api/v1/timetable-slots/{$slot->id}")->assertForbidden();

    foreach (['director', 'psychopedagogue'] as $role) {
        $user = User::factory()->forSchool($school)->{$role}()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/timetable-slots')->assertForbidden();
        $this->patchJson("/api/v1/timetable-slots/{$slot->id}", ['room' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/timetable-slots/{$slot->id}")->assertForbidden();
        expect($user->can('view', $slot))->toBeFalse();
    }

    expect($slot->fresh()->room)->not->toBe('X');
});

it('lets the owner edit and remove a slot', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $subject = leadGroup($group, $teacher);
    $slot = TimetableSlot::factory()->create([
        'school_id' => $school->id, 'teacher_id' => $teacher->id, 'group_id' => $group->id, 'subject_id' => $subject->id,
        'weekday' => 1, 'start_time' => '08:00',
    ]);
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/timetable-slots/{$slot->id}", ['weekday' => 4, 'room' => 'Aula 4'])
        ->assertOk()->assertJsonPath('data.weekday', 4)->assertJsonPath('data.room', 'Aula 4');

    // Moving it to a group where the teacher does not teach that subject.
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($otherGroup, $teacher);
    $this->patchJson("/api/v1/timetable-slots/{$slot->id}", ['group_id' => $otherGroup->id])
        ->assertUnprocessable()->assertJsonValidationErrorFor('subject_id');

    $this->deleteJson("/api/v1/timetable-slots/{$slot->id}")->assertNoContent();
    expect(TimetableSlot::count())->toBe(0);
});

it('returns 404 for another school\'s slot (tenant isolation)', function () {
    $schoolB = School::factory()->create();
    $ownerB = User::factory()->forSchool($schoolB)->teacher()->create();
    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);
    $slotB = TimetableSlot::factory()->create([
        'school_id' => $schoolB->id, 'teacher_id' => $ownerB->id, 'group_id' => $groupB->id, 'subject_id' => leadGroup($groupB, $ownerB)->id,
    ]);
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->teacher()->create());

    $this->patchJson("/api/v1/timetable-slots/{$slotB->id}", ['room' => 'X'])->assertNotFound();
    $this->deleteJson("/api/v1/timetable-slots/{$slotB->id}")->assertNotFound();
});

it('rejects a group from another school', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $groupB = Group::factory()->create(['school_id' => School::factory()->create()->id]);
    $subject = Subject::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/timetable-slots', ['group_id' => $groupB->id, 'subject_id' => $subject->id, 'weekday' => 1, 'start_time' => '08:00'])
        ->assertUnprocessable()->assertJsonValidationErrorFor('group_id');
});
