<?php

use App\Models\Assessment;
use App\Models\Group;
use App\Models\School;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Helper: a teacher leading a fresh group in the given school.
 *
 * @return array{0: User, 1: Group}
 */
function teacherLeadingGroup(School $school): array
{
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);

    return [$teacher, $group];
}

it('lets the teacher who leads a group create an assessment for it', function () {
    $school = School::factory()->create();
    [$teacher, $group] = teacherLeadingGroup($school);
    Sanctum::actingAs($teacher);

    $response = $this->postJson("/api/v1/groups/{$group->id}/assessments", [
        'type' => 'written',
        'purpose' => 'Diagnóstico inicial',
        'duration_minutes' => 45,
        'administered_at' => '2026-03-10',
    ])->assertCreated();

    $response->assertJsonPath('data.type', 'written')
        ->assertJsonPath('data.administered_at', '2026-03-10')
        ->assertJsonPath('data.teacher_id', $teacher->id)
        ->assertJsonPath('data.group_id', $group->id);

    $this->assertDatabaseHas('assessments', [
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
    ]);
    expect(Assessment::first()->administered_at->toDateString())->toBe('2026-03-10');
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
