<?php

use App\Models\Group;
use App\Models\School;
use App\Models\SchoolGrade;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\SchoolYears\SchoolYearCalendar;
use Laravel\Sanctum\Sanctum;

// Screen 23 «Recursos disponibles» (ClickUp 1dfd3mc4bh4): Dirección loads the
// school's resources; each group's teachers uncheck what the group does not
// have and add their own; Psicopedagogía reads.

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->director = User::factory()->forSchool($this->school)->director()->create();
    $this->psycho = User::factory()->forSchool($this->school)->psychopedagogue()->create();
    $this->teacher = User::factory()->forSchool($this->school)->teacher()->create(['name' => 'Ana']);
    $this->otherTeacher = User::factory()->forSchool($this->school)->teacher()->create();
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    leadGroup($this->group, $this->teacher);
});

it('lets only Dirección load and remove the school resources', function () {
    Sanctum::actingAs($this->teacher);
    $this->postJson('/api/v1/school-resources', ['name' => 'Huerta escolar'])->assertForbidden();
    $this->getJson('/api/v1/school-resources')->assertForbidden();

    Sanctum::actingAs($this->director);
    $this->postJson('/api/v1/school-resources', ['name' => ''])->assertUnprocessable();
    $id = $this->postJson('/api/v1/school-resources', ['name' => 'Huerta escolar'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/school-resources', ['name' => 'Laboratorio de ciencias'])->assertCreated();
    $this->getJson('/api/v1/school-resources')->assertOk()->assertJsonPath('data.0.name', 'Huerta escolar')->assertJsonCount(2, 'data');

    $this->deleteJson("/api/v1/school-resources/{$id}")->assertNoContent();
    $this->getJson('/api/v1/school-resources')->assertJsonCount(1, 'data');
});

it('lets the group teacher uncheck a school resource and add the group own', function () {
    Sanctum::actingAs($this->director);
    $huerta = $this->postJson('/api/v1/school-resources', ['name' => 'Huerta escolar'])->json('data.id');

    Sanctum::actingAs($this->teacher);
    $this->getJson("/api/v1/groups/{$this->group->id}/resources")->assertOk()
        ->assertJsonPath('data.can_manage', true)
        ->assertJsonPath('data.school.0.available', true);
    $this->putJson("/api/v1/groups/{$this->group->id}/resources/school/{$huerta}", ['available' => false])->assertOk()
        ->assertJsonPath('data.school.0.available', false);
    $this->postJson("/api/v1/groups/{$this->group->id}/resources", ['name' => 'Tele en el salón'])->assertCreated()
        ->assertJsonPath('data.group.0.name', 'Tele en el salón')
        ->assertJsonPath('data.group.0.created_by_name', 'Ana');

    // The exclusion is per group.
    $other = Group::factory()->create(['school_id' => $this->school->id]);
    Sanctum::actingAs($this->director);
    $this->getJson("/api/v1/groups/{$other->id}/resources")->assertJsonPath('data.school.0.available', true);
});

it('keeps Psicopedagogía and other teachers out of the group resources writes', function () {
    Sanctum::actingAs($this->director);
    $huerta = $this->postJson('/api/v1/school-resources', ['name' => 'Huerta escolar'])->json('data.id');
    $own = $this->postJson("/api/v1/groups/{$this->group->id}/resources", ['name' => 'Tele'])->json('data.group.0.id');

    Sanctum::actingAs($this->psycho);
    $this->getJson("/api/v1/groups/{$this->group->id}/resources")->assertOk()->assertJsonPath('data.can_manage', false);
    $this->putJson("/api/v1/groups/{$this->group->id}/resources/school/{$huerta}", ['available' => false])->assertForbidden();
    $this->deleteJson("/api/v1/groups/{$this->group->id}/resources/{$own}")->assertForbidden();

    Sanctum::actingAs($this->otherTeacher);
    $this->getJson("/api/v1/groups/{$this->group->id}/resources")->assertForbidden();
    $this->postJson("/api/v1/groups/{$this->group->id}/resources", ['name' => 'Proyector'])->assertForbidden();

    Sanctum::actingAs($this->teacher);
    $this->deleteJson("/api/v1/groups/{$this->group->id}/resources/{$own}")->assertOk()->assertJsonCount(0, 'data.group');
});

it('never mixes schools', function () {
    $otherSchool = School::factory()->create();
    $otherDirector = User::factory()->forSchool($otherSchool)->director()->create();
    Sanctum::actingAs($otherDirector);
    $foreign = $this->postJson('/api/v1/school-resources', ['name' => 'Piscina'])->json('data.id');

    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/school-resources')->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/school-resources/{$foreign}")->assertNotFound();
    $this->putJson("/api/v1/groups/{$this->group->id}/resources/school/{$foreign}", ['available' => false])->assertNotFound();
});

it('names the grades as in Uruguay', function () {
    expect(SchoolGrade::query()->orderBy('position')->pluck('label')->all())->toBe([
        'Inicial 3 años', 'Inicial 4 años', 'Inicial 5 años',
        '1.° de Primaria', '2.° de Primaria', '3.° de Primaria', '4.° de Primaria', '5.° de Primaria', '6.° de Primaria',
        '7°CB', '8°CB', '9°CB', '1°EMS', '2°EMS', '3°EMS',
    ]);
});

it('rejects nested, cross-group and cross-school access to the group resources', function () {
    $otherGroup = Group::factory()->create(['school_id' => $this->school->id]);
    leadGroup($otherGroup, $this->otherTeacher);
    Sanctum::actingAs($this->otherTeacher);
    $foreignOwn = $this->postJson("/api/v1/groups/{$otherGroup->id}/resources", ['name' => 'Proyector'])->assertCreated()->json('data.group.0.id');

    // A teacher of the group cannot reach another group's resource through their own group (IDOR).
    Sanctum::actingAs($this->teacher);
    $this->deleteJson("/api/v1/groups/{$this->group->id}/resources/{$foreignOwn}")->assertNotFound();
    $this->deleteJson("/api/v1/groups/{$otherGroup->id}/resources/{$foreignOwn}")->assertForbidden();
    $this->putJson("/api/v1/groups/{$this->group->id}/resources/school/999999", ['available' => false])->assertNotFound();

    Sanctum::actingAs($this->director);
    $huerta = $this->postJson('/api/v1/school-resources', ['name' => 'Huerta escolar'])->json('data.id');
    Sanctum::actingAs($this->otherTeacher);
    $this->putJson("/api/v1/groups/{$this->group->id}/resources/school/{$huerta}", ['available' => false])->assertForbidden();
    Sanctum::actingAs($this->teacher);
    $this->putJson("/api/v1/groups/{$this->group->id}/resources/school/{$huerta}", ['available' => 'quizás'])->assertUnprocessable();
    $this->postJson("/api/v1/groups/{$this->group->id}/resources", ['name' => ''])->assertUnprocessable();

    // Another school's director gets 404 on every group route.
    $stranger = User::factory()->forSchool(School::factory()->create())->director()->create();
    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/groups/{$this->group->id}/resources")->assertNotFound();
    $this->postJson("/api/v1/groups/{$this->group->id}/resources", ['name' => 'x'])->assertNotFound();
    $this->putJson("/api/v1/groups/{$this->group->id}/resources/school/{$huerta}", ['available' => false])->assertNotFound();
    $this->deleteJson("/api/v1/groups/{$otherGroup->id}/resources/{$foreignOwn}")->assertNotFound();
});

it('marks the card read-only and rejects writes in a closed year', function () {
    SchoolYear::query()->forceCreate([
        'school_id' => $this->school->id, 'year' => $this->group->school_year, 'status' => 'closed', 'closed_at' => now(),
    ]);
    app(SchoolYearCalendar::class)->forget();

    Sanctum::actingAs($this->teacher);
    $this->getJson("/api/v1/groups/{$this->group->id}/resources")->assertOk()->assertJsonPath('data.can_manage', false);
    $this->postJson("/api/v1/groups/{$this->group->id}/resources", ['name' => 'Tele'])
        ->assertForbidden()->assertJsonPath('code', 'school_year_closed');
});
