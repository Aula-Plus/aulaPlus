<?php

// api/tests/Feature/InvitationShowTest.php

use App\Models\User;
use App\Models\UserInvitation;

use function Pest\Laravel\getJson;

it('returns the invitee email and school for a valid token, no auth', function () {
    $user = User::factory()->create(['password' => null, 'email' => 'invitee@example.com']);
    $token = UserInvitation::issueFor($user);

    getJson("/api/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.email', 'invitee@example.com')
        ->assertJsonPath('data.school_name', $user->school->name);
});

it('returns 410 for an unknown token', function () {
    getJson('/api/invitations/does-not-exist')->assertStatus(410);
});
