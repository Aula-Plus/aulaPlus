<?php

// api/tests/Feature/UserInvitationSchemaTest.php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('has the invitation lifecycle columns and table', function () {
    expect(Schema::hasColumn('users', 'disabled_at'))->toBeTrue();
    expect(Schema::hasColumns('user_invitations', [
        'user_id', 'token', 'expires_at', 'accepted_at',
    ]))->toBeTrue();
});

it('allows a user with a null password', function () {
    $user = User::factory()->create(['password' => null]);
    expect($user->fresh()->password)->toBeNull();
});
