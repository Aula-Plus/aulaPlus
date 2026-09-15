<?php

use App\Models\Assessment;
use App\Models\Group;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Helper: a teacher leading a fresh group in the given school, plus the subject
 * they are assigned in that group (assessments now require a subject the teacher
 * actually teaches there).
 *
 * @return array{0: User, 1: Group, 2: Subject}
 */
function teacherLeadingGroup(School $school): array
{
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $subject = leadGroup($group, $teacher);

    return [$teacher, $group, $subject];
}

it('lets the teacher who leads a group create an assessment for it', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = teacherLeadingGroup($school);
    Sanctum::actingAs($teacher);

    $response = $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written',
        'purpose' => 'Diagnóstico inicial',
        'duration_minutes' => 45,
        'administered_at' => '2026-03-10',
        'subject_id' => $subject->id,
    ])->assertCreated();

    $response->assertJsonPath('data.type', 'written')
        ->assertJsonPath('data.administered_at', '2026-03-10')
        ->assertJsonPath('data.teacher_id', $teacher->id)
        ->assertJsonPath('data.group_id', $group->id)
        ->assertJsonPath('data.subject_id', $subject->id);

    $this->assertDatabaseHas('assessments', [
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
    ]);
    expect(Assessment::first()->administered_at->toDateString())->toBe('2026-03-10');
});

it('rejects an assessment for a subject the teacher is not assigned in the group', function () {
    $school = School::factory()->create();
    [$teacher, $group] = teacherLeadingGroup($school);
    // A different subject in the same school, NOT assigned to this teacher/group.
    $otherSubject = Subject::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written',
        'administered_at' => '2026-03-10',
        'subject_id' => $otherSubject->id,
    ])->assertUnprocessable()->assertJsonValidationErrorFor('subject_id');

    $this->assertDatabaseCount('assessments', 0);
});

it('requires subject_id when creating an assessment', function () {
    $school = School::factory()->create();
    [$teacher, $group] = teacherLeadingGroup($school);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written',
        'administered_at' => '2026-03-10',
    ])->assertUnprocessable()->assertJsonValidationErrorFor('subject_id');
});

it('rejects a subject from another school (tenant isolation)', function () {
    $school = School::factory()->create();
    [$teacher, $group] = teacherLeadingGroup($school);
    $foreignSubject = Subject::factory()->create(['school_id' => School::factory()->create()->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written',
        'administered_at' => '2026-03-10',
        'subject_id' => $foreignSubject->id,
    ])->assertUnprocessable()->assertJsonValidationErrorFor('subject_id');
});

it('requires administered_at when creating an assessment', function () {
    $school = School::factory()->create();
    [$teacher, $group] = teacherLeadingGroup($school);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written',
    ])->assertUnprocessable()->assertJsonValidationErrorFor('administered_at');
});

// docs/prompts/13-evaluaciones-resultados.md §3: the group-specific check lives
// in the controller/request, not the (untouched) Session 2 policy — a teacher
// who has the teacher role but does not lead the group is still forbidden.
it('forbids a teacher from creating an assessment for a group they do not lead', function () {
    $school = School::factory()->create();
    $outsider = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($outsider);

    $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written',
        'administered_at' => '2026-03-10',
    ])->assertForbidden();

    $this->assertDatabaseCount('assessments', 0);
});

it('forbids a director from creating an assessment (not a teacher role)', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written',
        'administered_at' => '2026-03-10',
    ])->assertForbidden();
});

it('lists the assessments of a group ordered by administered_at desc', function () {
    $school = School::factory()->create();
    [$teacher, $group] = teacherLeadingGroup($school);
    Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id, 'administered_at' => '2026-03-01']);
    Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id, 'administered_at' => '2026-05-01']);
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/groups/{$group->id}/assessments")->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.administered_at'))->toBe('2026-05-01');
});

it('lets the owning teacher update their assessment', function () {
    $school = School::factory()->create();
    [$teacher, $group] = teacherLeadingGroup($school);
    $assessment = Assessment::factory()->create([
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
        'administered_at' => '2026-03-01',
    ]);
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/assessments/{$assessment->id}", [
        'purpose' => 'Actualizado',
        'administered_at' => '2026-04-15',
    ])->assertOk()
        ->assertJsonPath('data.purpose', 'Actualizado')
        ->assertJsonPath('data.administered_at', '2026-04-15');
});

it('lets the owning teacher change the subject to another they teach in the group', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = teacherLeadingGroup($school);
    $secondSubject = leadGroup($group, $teacher); // assigns a second subject in the group
    $assessment = Assessment::factory()->create([
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
    ]);
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/assessments/{$assessment->id}", [
        'subject_id' => $secondSubject->id,
    ])->assertOk()->assertJsonPath('data.subject_id', $secondSubject->id);
});

it('rejects updating an assessment to a subject the teacher is not assigned in its group', function () {
    $school = School::factory()->create();
    [$teacher, $group, $subject] = teacherLeadingGroup($school);
    $unassigned = Subject::factory()->create(['school_id' => $school->id]);
    $assessment = Assessment::factory()->create([
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
    ]);
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/assessments/{$assessment->id}", [
        'subject_id' => $unassigned->id,
    ])->assertUnprocessable()->assertJsonValidationErrorFor('subject_id');
});

it('forbids a non-owner teacher from updating or deleting an assessment', function () {
    $school = School::factory()->create();
    [$owner, $group] = teacherLeadingGroup($school);
    $other = User::factory()->forSchool($school)->teacher()->create();
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $owner->id]);
    Sanctum::actingAs($other);

    $this->patchJson("/api/v1/assessments/{$assessment->id}", ['purpose' => 'x'])->assertForbidden();
    $this->deleteJson("/api/v1/assessments/{$assessment->id}")->assertForbidden();
});

it('lets the owning teacher delete their assessment', function () {
    $school = School::factory()->create();
    [$teacher, $group] = teacherLeadingGroup($school);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id]);
    Sanctum::actingAs($teacher);

    $this->deleteJson("/api/v1/assessments/{$assessment->id}")->assertNoContent();

    $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
});

it('never resolves an assessment from another school (tenant isolation)', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    [$ownerB, $groupB] = teacherLeadingGroup($schoolB);
    $assessmentB = Assessment::factory()->create(['group_id' => $groupB->id, 'teacher_id' => $ownerB->id]);
    Sanctum::actingAs($directorA);

    // The SchoolScope hides it entirely, so route-model binding 404s.
    $this->patchJson("/api/v1/assessments/{$assessmentB->id}", ['purpose' => 'x'])->assertNotFound();
    $this->getJson("/api/v1/groups/{$groupB->id}/assessments")->assertNotFound();
});
