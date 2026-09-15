<?php

// api/tests/Feature/LoginLifecycleTest.php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\postJson;

it('blocks a pending user with a clear message', function () {
    User::factory()->create(['email' => 'pending@example.com', 'password' => null]);

    postJson('/api/login', ['email' => 'pending@example.com', 'password' => 'whatever1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'La cuenta aún no fue activada. Revisá tu correo.');
});

it('blocks a disabled user even with the right password', function () {
    User::factory()->create([
        'email' => 'gone@example.com',
        'password' => Hash::make('correct-horse1'),
        'disabled_at' => now(),
    ]);

    postJson('/api/login', ['email' => 'gone@example.com', 'password' => 'correct-horse1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Esta cuenta está desactivada.');
});
