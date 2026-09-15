<?php

// api/tests/Feature/UserDisableTest.php

use App\Models\User;
use Database\Seeders\RoleSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->director = User::factory()->director()->create();
});

it('disables and re-enables a user in the same school', function () {
    $target = User::factory()->teacher()->create(['school_id' => $this->director->school_id]);

    actingAs($this->director)->postJson("/api/v1/users/{$target->id}/disable")
        ->assertOk()->assertJsonPath('data.status', 'disabled');
    expect($target->refresh()->isDisabled())->toBeTrue();

    actingAs($this->director)->postJson("/api/v1/users/{$target->id}/enable")
        ->assertOk()->assertJsonPath('data.status', 'active');
    expect($target->refresh()->isDisabled())->toBeFalse();
});

it('refuses to disable yourself or the last active director', function () {
    // Only director in the school → cannot disable self.
    actingAs($this->director)->postJson("/api/v1/users/{$this->director->id}/disable")
        ->assertStatus(422);

    // A second director exists → still cannot disable self, but CAN disable the other.
    $second = User::factory()->director()->create(['school_id' => $this->director->school_id]);
    actingAs($this->director)->postJson("/api/v1/users/{$this->director->id}/disable")
        ->assertStatus(422);
    actingAs($this->director)->postJson("/api/v1/users/{$second->id}/disable")
        ->assertOk();
});
