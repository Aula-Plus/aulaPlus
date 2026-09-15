<?php

use App\Enums\Role;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RoleSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
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

it('lists subjects for the current school only', function () {
    $otherSchool = School::factory()->create();
    Subject::factory()->for($otherSchool)->create(['name' => 'Foreign']);
    Subject::factory()->for($this->school)->create(['name' => 'Matemática']);

    actingAs(makeUser($this->school, Role::Teacher));

    getJson('/api/v1/subjects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Matemática');
});

it('rejects a duplicate subject name in the same school', function () {
    Subject::factory()->for($this->school)->create(['name' => 'Matemática']);
    actingAs(makeUser($this->school, Role::Director));

    postJson('/api/v1/subjects', ['name' => 'Matemática'])
        ->assertStatus(422);
});

it('allows reusing the name of a soft-deleted subject', function () {
    $subject = Subject::factory()->for($this->school)->create(['name' => 'Matemática']);
    $subject->delete();

    actingAs(makeUser($this->school, Role::Director));

    // The partial unique index ignores soft-deleted rows, so recreating a
    // subject with a deleted subject's name must succeed (not 422 or 500).
    postJson('/api/v1/subjects', ['name' => 'Matemática'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Matemática');
});
