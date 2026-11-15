<?php

use App\Models\School;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * ClickUp 1dfd3mc4zpk: the «N evaluaciones hoy» total in Psychopedagogy's
 * Inicio is on by default and each person hides or shows it; the value lives
 * on the server, per person.
 */
it('starts with the total shown and keeps each person’s own choice', function () {
    $school = School::factory()->create();
    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create();
    $other = User::factory()->forSchool($school)->psychopedagogue()->create();

    Sanctum::actingAs($psycho);
    $this->getJson('/api/v1/me/preferences')->assertOk()
        ->assertJsonPath('data.show_today_assessments_total', true);

    $this->patchJson('/api/v1/me/preferences', ['show_today_assessments_total' => false])->assertOk()
        ->assertJsonPath('data.show_today_assessments_total', false);
    $this->getJson('/api/v1/me/preferences')->assertJsonPath('data.show_today_assessments_total', false);

    // Someone else's preference is untouched.
    Sanctum::actingAs($other);
    $this->getJson('/api/v1/me/preferences')->assertJsonPath('data.show_today_assessments_total', true);
});

it('validates the preference and requires a session', function () {
    $user = User::factory()->forSchool(School::factory()->create())->teacher()->create();

    $this->getJson('/api/v1/me/preferences')->assertUnauthorized();

    Sanctum::actingAs($user);
    $this->patchJson('/api/v1/me/preferences', [])->assertUnprocessable()
        ->assertJsonValidationErrorFor('show_today_assessments_total');
    $this->patchJson('/api/v1/me/preferences', ['show_today_assessments_total' => 'maybe'])->assertUnprocessable();
});
