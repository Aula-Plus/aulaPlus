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
