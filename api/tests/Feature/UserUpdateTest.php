<?php

// api/tests/Feature/UserUpdateTest.php

use App\Models\User;
use Database\Seeders\RoleSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->director = User::factory()->director()->create();
});

it('updates name and role but never email', function () {
    $target = User::factory()->teacher()->create([
        'school_id' => $this->director->school_id,
        'email' => 'keep@example.com',
    ]);

    actingAs($this->director)->patchJson("/api/v1/users/{$target->id}", [
        'name' => 'Nombre Nuevo',
        'role' => 'psychopedagogue',
        'email' => 'hacker@example.com',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Nombre Nuevo')
        ->assertJsonPath('data.roles.0', 'psychopedagogue');

    $target->refresh();
    expect($target->email)->toBe('keep@example.com');
    expect($target->hasRole('teacher'))->toBeFalse();
});

it('forbids editing a user from another school', function () {
    $other = User::factory()->teacher()->create();
    actingAs($this->director)->patchJson("/api/v1/users/{$other->id}", ['name' => 'X'])
        ->assertForbidden();
});
