<?php

// api/tests/Feature/UserResendInvitationTest.php

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    $this->director = User::factory()->director()->create();
});

it('resends an invitation to a pending user', function () {
    $pending = User::factory()->teacher()->create([
        'school_id' => $this->director->school_id, 'password' => null,
    ]);

    actingAs($this->director)->postJson("/api/v1/users/{$pending->id}/resend-invitation")
        ->assertOk();

    Notification::assertSentTo($pending, UserInvitationNotification::class);
});

it('refuses to resend to an already-active user', function () {
    $active = User::factory()->teacher()->create(['school_id' => $this->director->school_id]);

    actingAs($this->director)->postJson("/api/v1/users/{$active->id}/resend-invitation")
        ->assertStatus(422);
    Notification::assertNothingSent();
});
