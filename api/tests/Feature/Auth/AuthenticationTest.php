<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * The login endpoint establishes a first-party session, so requests must look
 * like they come from the SPA (stateful Referer). /me and /logout are tested
 * with Sanctum::actingAs since the array session driver does not persist
 * cookies across test requests.
 */
function login(array $payload)
{
    // A stateful Referer triggers Sanctum's session middleware on the api group.
    return test()->withHeader('Referer', config('app.url'))->postJson('/api/login', $payload);
}

it('logs in a valid user and returns their identity', function () {
    $school = School::factory()->create();
    $user = User::factory()->forSchool($school)->teacher()->create([
        'email' => 'ana@escuela.test',
        'password' => Hash::make('secret-pass-1'),
    ]);

    login(['email' => 'ana@escuela.test', 'password' => 'secret-pass-1'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'ana@escuela.test')
        ->assertJsonPath('user.school.id', $school->id)
        ->assertJsonPath('user.roles.0', 'teacher');
});

it('rejects invalid credentials', function () {
    User::factory()->create([
        'email' => 'ana@escuela.test',
        'password' => Hash::make('secret-pass-1'),
    ]);

    login(['email' => 'ana@escuela.test', 'password' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('email');
});

it('validates required login fields', function () {
    login([])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('requires authentication for /me', function () {
    $this->getJson('/api/me')->assertUnauthorized();
});

it('returns the authenticated user from /me', function () {
    $user = User::factory()->director()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.roles.0', 'director');
});

it('logs out an authenticated user', function () {
    Sanctum::actingAs(User::factory()->create());

    // A stateful Referer starts the session middleware, matching a real SPA logout.
    $this->withHeader('Referer', config('app.url'))
        ->postJson('/api/logout')
        ->assertOk();
});

it('throttles /login after the configured number of attempts', function () {
    config()->set('auth.login_max_attempts', 3);

    User::factory()->create([
        'email' => 'ana@escuela.test',
        'password' => Hash::make('secret-pass-1'),
    ]);

    // Valid credentials, so the per-email attempt limiter (LoginRequest) never
    // fires — this isolates the route-level throttle middleware.
    $payload = ['email' => 'ana@escuela.test', 'password' => 'secret-pass-1'];

    for ($i = 0; $i < 3; $i++) {
        login($payload)->assertOk();
    }

    login($payload)->assertStatus(429);
});

it('allows more /login attempts when the env limit is raised', function () {
    // Simulates the relaxed local-dev setting; prod keeps the strict default.
    config()->set('auth.login_max_attempts', 20);

    User::factory()->create([
        'email' => 'ana@escuela.test',
        'password' => Hash::make('secret-pass-1'),
    ]);

    $payload = ['email' => 'ana@escuela.test', 'password' => 'secret-pass-1'];

    for ($i = 0; $i < 7; $i++) {
        login($payload)->assertOk();
    }
});
