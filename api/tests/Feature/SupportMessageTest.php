<?php

use App\Models\School;
use App\Models\SupportMessage;
use App\Models\User;
use App\Notifications\SupportMessageNotification;
use App\Support\Tenancy;
use Illuminate\Notifications\AnonymousNotifiable;
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

    $this->postJson('/api/v1/support-messages', [
        'kind' => 'improvement',
        'message' => 'Poder imprimir la ficha',
        'screen' => '/alumnos/3/seguimiento',
        'screen_name' => 'Perfil del alumno',
    ])->assertCreated();

    Notification::assertNothingSent();
    $this->assertDatabaseHas('support_messages', ['kind' => 'improvement', 'screen' => '/alumnos/3/seguimiento']);
});

it('forbids an authenticated user without a staff role', function () {
    Notification::fake();
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->create());

    $this->postJson('/api/v1/support-messages', ['kind' => 'issue', 'message' => 'No guarda nada'])
        ->assertForbidden();

    Notification::assertNothingSent();
    expect(SupportMessage::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('builds the queued mail outside any tenant context (as a queue worker would)', function () {
    $school = School::factory()->create(['name' => 'Colegio Norte']);
    $user = User::factory()->forSchool($school)->teacher()->create(['name' => 'Ana Pérez']);
    $message = Tenancy::forSchool($school, fn () => SupportMessage::create([
        'user_id' => $user->id,
        'kind' => SupportMessage::KIND_ISSUE,
        'message' => 'No me deja guardar las notas',
        'role' => 'teacher',
        'screen' => '/evaluaciones',
    ]));

    // Round-trip through queue serialization, then render with no tenant set.
    $notification = unserialize(serialize(new SupportMessageNotification($message)));
    Tenancy::forget();
    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('[Aula+] Algo no funciona')
        ->and($mail->introLines)->toContain('No me deja guardar las notas')
        ->and(implode("\n", $mail->introLines))->toContain('Ana Pérez')
        ->toContain('Colegio Norte')
        ->toContain('/evaluaciones');
});

it('names the screen, role and school in the mail and stores only the route path', function () {
    Notification::fake();
    config(['services.support.notify_to' => ['equipo@aulaplus.test']]);
    $school = School::factory()->create(['name' => 'Ivy Thomas']);
    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create(['name' => 'Ana Pérez']));

    $this->postJson('/api/v1/support-messages', [
        'kind' => 'issue',
        'message' => 'No me deja guardar las notas',
        'screen' => '/clases/12/evaluaciones',
        'screen_name' => 'Evaluaciones',
    ])->assertCreated();

    $this->assertDatabaseHas('support_messages', ['screen' => '/clases/12/evaluaciones']);

    Notification::assertSentOnDemand(
        SupportMessageNotification::class,
        function (SupportMessageNotification $notification) {
            // Round-trip through queue serialization, as a worker would.
            $mail = unserialize(serialize($notification))->toMail(new AnonymousNotifiable);

            return in_array('Desde: Evaluaciones (/clases/12/evaluaciones) — Docente — Ivy Thomas', $mail->introLines, true)
                && in_array('Enviado por: Ana Pérez', $mail->introLines, true);
        },
    );
});

it('falls back to whatever part of the screen is known', function (?string $path, ?string $name, string $expected) {
    $school = School::factory()->create(['name' => 'Ivy Thomas']);
    $user = User::factory()->forSchool($school)->director()->create();
    $message = Tenancy::forSchool($school, fn () => SupportMessage::create([
        'user_id' => $user->id,
        'kind' => SupportMessage::KIND_IMPROVEMENT,
        'message' => 'Poder imprimir la ficha',
        'role' => 'director',
        'screen' => $path,
    ]));

    $mail = (new SupportMessageNotification($message, $name))->toMail(new AnonymousNotifiable);

    expect($mail->introLines)->toContain("Desde: {$expected} — Director — Ivy Thomas");
})->with([
    'path only' => ['/alumnos/3', null, '/alumnos/3'],
    'empty name' => ['/alumnos/3', '', '/alumnos/3'],
    'nothing known' => [null, null, 'pantalla no informada'],
]);

it('validates the screen name', function () {
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->teacher()->create());

    $this->postJson('/api/v1/support-messages', [
        'kind' => 'issue',
        'message' => 'No guarda nada',
        'screen_name' => str_repeat('a', 101),
    ])->assertUnprocessable()->assertJsonValidationErrors('screen_name');

    $this->postJson('/api/v1/support-messages', [
        'kind' => 'issue',
        'message' => 'No guarda nada',
        'screen_name' => ['Evaluaciones'],
    ])->assertUnprocessable()->assertJsonValidationErrors('screen_name');

    expect(SupportMessage::query()->count())->toBe(0);
});
