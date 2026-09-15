<?php

// api/tests/Feature/UserIndexTest.php

use App\Models\User;
use Database\Seeders\RoleSeeder;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('lists staff in the caller school with a derived status, director only', function () {
    $director = User::factory()->director()->create();
    $active = User::factory()->teacher()->create(['school_id' => $director->school_id]);
    $pending = User::factory()->teacher()->create(['school_id' => $director->school_id, 'password' => null]);
    User::factory()->teacher()->create(); // other school — must not appear

    actingAs($director)->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonCount(3, 'data') // director + active + pending
        ->assertJsonFragment(['id' => $pending->id, 'status' => 'pending'])
        ->assertJsonFragment(['id' => $active->id, 'status' => 'active']);
});

it('forbids non-directors', function () {
    $teacher = User::factory()->teacher()->create();
    actingAs($teacher)->getJson('/api/v1/users')->assertForbidden();
});
