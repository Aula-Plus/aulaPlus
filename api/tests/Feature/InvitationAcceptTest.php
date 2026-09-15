<?php

// api/tests/Feature/InvitationAcceptTest.php

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\postJson;

it('sets the password, activates the user, and consumes the token', function () {
    $user = User::factory()->create(['password' => null]);
    $token = UserInvitation::issueFor($user);

    postJson("/api/invitations/{$token}/accept", [
        'password' => 'sup3rsecret',
        'password_confirmation' => 'sup3rsecret',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('sup3rsecret', $user->password))->toBeTrue();
    expect($user->isPending())->toBeFalse();

    // Single-use: the token no longer works.
    postJson("/api/invitations/{$token}/accept", [
        'password' => 'anotherpass1',
        'password_confirmation' => 'anotherpass1',
    ])->assertStatus(410);
});

it('rejects a weak or unconfirmed password', function () {
    $user = User::factory()->create(['password' => null]);
    $token = UserInvitation::issueFor($user);

    postJson("/api/invitations/{$token}/accept", [
        'password' => 'short', 'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrorFor('password');
});
