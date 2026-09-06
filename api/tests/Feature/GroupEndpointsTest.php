<?php

use App\Models\Group;
use App\Models\School;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets a director list, create, update and delete groups in their school', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $create = $this->postJson('/api/groups', ['name' => '3° A', 'level' => 'Primaria', 'year' => '2026']);
    $create->assertCreated()->assertJsonPath('data.name', '3° A');
    $groupId = $create->json('data.id');

    $this->getJson('/api/groups')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson("/api/groups/{$groupId}", ['name' => '3° B'])
        ->assertOk()
        ->assertJsonPath('data.name', '3° B');

    $this->deleteJson("/api/groups/{$groupId}")->assertNoContent();
    $this->getJson('/api/groups')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects a teacher trying to create, update or delete a group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    Sanctum::actingAs($teacher);

    $this->postJson('/api/groups', ['name' => '3° A'])->assertForbidden();
    $this->putJson("/api/groups/{$group->id}", ['name' => 'x'])->assertForbidden();
    $this->deleteJson("/api/groups/{$group->id}")->assertForbidden();
});

it('lists only the groups a teacher leads, but all groups for school-wide roles', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    Group::factory()->create(['school_id' => $school->id]);

    Sanctum::actingAs($teacher);
    $this->getJson('/api/groups')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($director);
    $this->getJson('/api/groups')->assertOk()->assertJsonCount(2, 'data');
});

it('never exposes or modifies another school\'s group', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);

    Sanctum::actingAs($directorA);

    $this->getJson("/api/groups/{$groupB->id}")->assertNotFound();
    $this->putJson("/api/groups/{$groupB->id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/groups/{$groupB->id}")->assertNotFound();
});
