<?php

use App\Models\School;
use App\Models\SupportMessage;
use App\Models\User;
use App\Notifications\SupportMessageNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->postJson('/api/v1/support-messages', ['kind' => 'issue', 'message' => 'No guarda'])
        ->assertUnauthorized();
});

it('stores the message with sender, role, school and screen taken from the session', function (string $factoryState, string $role) {
    Notification::fake();
    config(['services.support.notify_to' => ['equipo@aulaplus.test']]);
    $school = School::factory()->create();
    $user = User::factory()->forSchool($school)->{$factoryState}()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/support-messages', [
        'kind' => 'issue',
        'message' => 'No me deja guardar las notas',
        'screen' => 'Evaluaciones — 2°EMS-A',
        // Client-supplied identity fields must be ignored.
        'role' => 'director',
        'school_id' => 999,
        'user_id' => 999,
    ])->assertCreated();

    $this->assertDatabaseHas('support_messages', [
        'school_id' => $school->id,
        'user_id' => $user->id,
        'role' => $role,
        'kind' => 'issue',
        'screen' => 'Evaluaciones — 2°EMS-A',
    ]);

    Notification::assertSentOnDemand(
        SupportMessageNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === ['equipo@aulaplus.test'],
    );
})->with([
    'teacher' => ['teacher', 'teacher'],
    'psychopedagogue' => ['psychopedagogue', 'psychopedagogue'],
    'director' => ['director', 'director'],
]);

it('validates kind and message', function () {
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->teacher()->create());

    $this->postJson('/api/v1/support-messages', ['kind' => 'complaint', 'message' => 'hola mundo'])
        ->assertUnprocessable()->assertJsonValidationErrors('kind');
    $this->postJson('/api/v1/support-messages', ['kind' => 'issue', 'message' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('message');
    $this->postJson('/api/v1/support-messages', ['kind' => 'issue', 'message' => str_repeat('a', 3001)])
        ->assertUnprocessable()->assertJsonValidationErrors('message');

    expect(SupportMessage::query()->count())->toBe(0);
});

it('still stores the message when no recipients are configured', function () {
    Notification::fake();
    config(['services.support.notify_to' => []]);
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->teacher()->create());

    $this->postJson('/api/v1/support-messages', ['kind' => 'improvement', 'message' => 'Poder imprimir la ficha'])
        ->assertCreated();

    Notification::assertNothingSent();
    expect(SupportMessage::query()->count())->toBe(1);
});
