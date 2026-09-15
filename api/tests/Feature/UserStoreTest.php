<?php

// api/tests/Feature/UserStoreTest.php

use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    $this->director = User::factory()->director()->create();
});

it('creates a pending user, assigns the role, and sends an invitation', function () {
    actingAs($this->director)->postJson('/api/v1/users', [
        'name' => 'Ana Docente',
        'email' => 'ana@example.com',
        'role' => 'teacher',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.roles.0', 'teacher');

    $created = User::firstWhere('email', 'ana@example.com');
    expect($created->school_id)->toBe($this->director->school_id);
    expect($created->password)->toBeNull();
    expect(UserInvitation::where('user_id', $created->id)->exists())->toBeTrue();
    Notification::assertSentTo($created, UserInvitationNotification::class);
});

it('rejects a duplicate email and an invalid role', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    actingAs($this->director)->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'taken@example.com', 'role' => 'teacher',
    ])->assertStatus(422)->assertJsonValidationErrorFor('email');

    actingAs($this->director)->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'new@example.com', 'role' => 'wizard',
    ])->assertStatus(422)->assertJsonValidationErrorFor('role');
});

it('forbids a non-director from creating users', function () {
    $teacher = User::factory()->teacher()->create();
    actingAs($teacher)->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'n@example.com', 'role' => 'teacher',
    ])->assertForbidden();
});
