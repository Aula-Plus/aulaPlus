<?php

use App\Enums\Role;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $this->school = School::factory()->create();
    Tenancy::useSchool($this->school);
});

function makeUser(School $school, Role $role): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role->value);

    return $user;
}

it('lets a director create a subject', function () {
    actingAs(makeUser($this->school, Role::Director));

    postJson('/api/v1/subjects', ['name' => 'Matemática'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Matemática');
});

it('forbids a teacher from creating a subject', function () {
    actingAs(makeUser($this->school, Role::Teacher));

    postJson('/api/v1/subjects', ['name' => 'Matemática'])
        ->assertForbidden();
});
