<?php

use App\Enums\Role;
use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RoleSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->group = Group::factory()->create();
    Tenancy::useSchool($this->group->school_id);
    $this->subject = Subject::factory()->for($this->group->school)->create(['name' => 'Matemática']);
    $this->teacher = User::factory()->create(['school_id' => $this->group->school_id]);
    $this->teacher->assignRole(Role::Teacher->value);
});

function director(Group $group): User
{
    $u = User::factory()->create(['school_id' => $group->school_id]);
    $u->assignRole(Role::Director->value);

    return $u;
}

it('lets a director assign a teacher to a subject in a group', function () {
    actingAs(director($this->group));

    postJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
    ])->assertCreated();

    expect($this->teacher->fresh()->teachesSubjectInGroup($this->group, $this->subject))->toBeTrue();
});

it('lists a group\'s teacher-subject assignments with names', function () {
    $this->group->teachers()->attach($this->teacher->id, ['subject_id' => $this->subject->id]);
    actingAs(director($this->group));

    getJson("/api/v1/groups/{$this->group->id}/teacher-assignments")
        ->assertOk()
        ->assertJsonPath('data.0.teacher_id', $this->teacher->id)
        ->assertJsonPath('data.0.subject_id', $this->subject->id)
        ->assertJsonPath('data.0.subject_name', 'Matemática');
});

it('forbids a teacher from creating assignments', function () {
    actingAs($this->teacher);

    postJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
    ])->assertForbidden();
});

it('removes a single (teacher, subject) assignment', function () {
    $this->group->teachers()->attach($this->teacher->id, ['subject_id' => $this->subject->id]);
    actingAs(director($this->group));

    deleteJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
    ])->assertNoContent();

    expect($this->teacher->fresh()->teachesSubjectInGroup($this->group, $this->subject))->toBeFalse();
});

it('lets a teacher hold two subjects in the same group (Option A: one row each)', function () {
    $english = Subject::factory()->for($this->group->school)->create(['name' => 'Inglés']);
    actingAs(director($this->group));

    postJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
    ])->assertCreated();

    // Assigning a second subject must ADD a row, not overwrite the first.
    postJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
        'teacher_id' => $this->teacher->id,
        'subject_id' => $english->id,
    ])->assertCreated();

    $teacher = $this->teacher->fresh();
    expect($teacher->teachesSubjectInGroup($this->group, $this->subject))->toBeTrue();
    expect($teacher->teachesSubjectInGroup($this->group, $english))->toBeTrue();
});

it('is idempotent on a repeated assignment', function () {
    actingAs(director($this->group));

    foreach (range(1, 2) as $ignored) {
        postJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
        ])->assertCreated();
    }

    $count = \Illuminate\Support\Facades\DB::table('group_teacher')
        ->where('group_id', $this->group->id)
        ->where('teacher_id', $this->teacher->id)
        ->where('subject_id', $this->subject->id)
        ->count();
    expect($count)->toBe(1);
});

it('removes only the named pair, leaving the teacher\'s other subject', function () {
    $english = Subject::factory()->for($this->group->school)->create(['name' => 'Inglés']);
    $this->group->teachers()->attach($this->teacher->id, ['subject_id' => $this->subject->id]);
    $this->group->teachers()->attach($this->teacher->id, ['subject_id' => $english->id]);
    actingAs(director($this->group));

    deleteJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
    ])->assertNoContent();

    $teacher = $this->teacher->fresh();
    expect($teacher->teachesSubjectInGroup($this->group, $this->subject))->toBeFalse();
    expect($teacher->teachesSubjectInGroup($this->group, $english))->toBeTrue();
});
