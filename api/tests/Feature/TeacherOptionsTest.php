<?php

use App\Models\School;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets a director list teachers in their own school, ordered by name', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    User::factory()->forSchool($school)->teacher()->create(['name' => 'Zoe Diaz']);
    User::factory()->forSchool($school)->teacher()->create(['name' => 'Ana Ruiz']);
    User::factory()->forSchool($school)->psychopedagogue()->create();

    Sanctum::actingAs($director);

    $this->getJson('/api/teachers')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Ana Ruiz')
        ->assertJsonPath('data.1.name', 'Zoe Diaz');
});

it('excludes teachers from other schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $director = User::factory()->forSchool($schoolA)->director()->create();
    User::factory()->forSchool($schoolB)->teacher()->create();

    Sanctum::actingAs($director);

    $this->getJson('/api/teachers')->assertOk()->assertJsonCount(0, 'data');
});

it('forbids non-directors from listing teachers', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();

    Sanctum::actingAs($teacher);

    $this->getJson('/api/teachers')->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/teachers')->assertUnauthorized();
});
