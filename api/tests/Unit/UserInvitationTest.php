<?php

// api/tests/Unit/UserInvitationTest.php

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('issues a plaintext token stored only as a hash', function () {
    $user = User::factory()->create(['password' => null]);

    $plain = UserInvitation::issueFor($user);

    expect($plain)->toBeString()->and(strlen($plain))->toBeGreaterThan(30);
    $row = UserInvitation::firstWhere('user_id', $user->id);
    expect($row->token)->toBe(hash('sha256', $plain))
        ->and($row->token)->not->toBe($plain);
});

it('finds a pending invitation by plaintext and rejects expired/accepted ones', function () {
    $user = User::factory()->create(['password' => null]);
    $plain = UserInvitation::issueFor($user);

    expect(UserInvitation::findPending($plain)?->user_id)->toBe($user->id);
    expect(UserInvitation::findPending('wrong-token'))->toBeNull();

    UserInvitation::findPending($plain)->markAccepted();
    expect(UserInvitation::findPending($plain))->toBeNull();
});

it('replaces a prior pending invitation when re-issued', function () {
    $user = User::factory()->create(['password' => null]);
    $first = UserInvitation::issueFor($user);
    $second = UserInvitation::issueFor($user);

    expect(UserInvitation::findPending($first))->toBeNull();
    expect(UserInvitation::findPending($second)?->user_id)->toBe($user->id);
    expect(UserInvitation::where('user_id', $user->id)->count())->toBe(1);
});
