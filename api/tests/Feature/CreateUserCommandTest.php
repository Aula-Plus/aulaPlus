<?php

use App\Models\School;
use App\Models\User;

/**
 * `php artisan app:create-user` remains the supported way to create the
 * first (real, production) user of a school — confirms it still works after
 * the domain-model migrations in this session (school_id, photo_url, roles).
 */
it('creates a staff user in an existing school via prompts', function () {
    $school = School::factory()->create(['name' => 'Escuela de Prueba']);

    $this->artisan('app:create-user')
        ->expectsQuestion('School', $school->id)
        ->expectsQuestion('Full name', 'Ana Pérez')
        ->expectsQuestion('Email', 'ana.perez@example.com')
        ->expectsQuestion('Role', 'director')
        ->expectsQuestion('Password', 'Password123')
        ->assertSuccessful();

    $user = User::where('email', 'ana.perez@example.com')->firstOrFail();

    expect($user->school_id)->toBe($school->id)
        ->and($user->hasRole('director'))->toBeTrue();
});

it('creates a new school when none exist yet', function () {
    $this->artisan('app:create-user')
        ->expectsQuestion('School name', 'Escuela Nueva')
        ->expectsQuestion('Full name', 'Bruno Silva')
        ->expectsQuestion('Email', 'bruno.silva@example.com')
        ->expectsQuestion('Role', 'teacher')
        ->expectsQuestion('Password', 'Password123')
        ->assertSuccessful();

    $school = School::where('name', 'Escuela Nueva')->firstOrFail();
    $user = User::where('email', 'bruno.silva@example.com')->firstOrFail();

    expect($user->school_id)->toBe($school->id)
        ->and($user->hasRole('teacher'))->toBeTrue();
});
