<?php

use App\Models\School;
use App\Models\SupportMessage;
use App\Models\User;
use App\Notifications\SupportMessageNotification;
use App\Support\Tenancy;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * The mail's intro lines as the reader sees them (they are HTML-encoded so
 * that CommonMark treats user input as literal text).
 *
 * @return array<int, string>
 */
function supportMailLines(MailMessage $mail): array
{
    return array_map(
        fn ($line) => html_entity_decode((string) $line, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        $mail->introLines,
    );
}

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
        ->and(supportMailLines($mail))->toContain('No me deja guardar las notas')
        ->and(implode("\n", supportMailLines($mail)))->toContain('Ana Pérez')
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

            return in_array('Desde: Evaluaciones (/clases/12/evaluaciones) — Docente — Ivy Thomas', supportMailLines($mail), true)
                && in_array('Enviado por: Ana Pérez', supportMailLines($mail), true);
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

    expect(supportMailLines($mail))->toContain("Desde: {$expected} — Dirección — Ivy Thomas");
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

    // Only plain screen names: no Markdown/HTML syntax (no links in the mail).
    foreach (['[Restablecer acceso](https://evil.example)', '<b>Inicio</b>', 'Inicio: https://evil.example'] as $name) {
        $this->postJson('/api/v1/support-messages', [
            'kind' => 'issue',
            'message' => 'No guarda nada',
            'screen_name' => $name,
        ])->assertUnprocessable()->assertJsonValidationErrors('screen_name');
    }

    expect(SupportMessage::query()->count())->toBe(0);
});

it('accepts every real screen name the SPA sends', function (string $name) {
    Notification::fake();
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->teacher()->create());

    $this->postJson('/api/v1/support-messages', [
        'kind' => 'issue',
        'message' => 'No guarda nada',
        'screen' => '/x',
        'screen_name' => $name,
    ])->assertCreated();
})->with([
    'Inicio', 'Grupos', 'Nuevo grupo', 'Perfil del alumno', 'Diseño de screenings',
    'Tablero de adopción', 'Ayuda y sugerencias', 'Configurar comentarios',
    // Shapes a future title may take.
    'Evaluaciones (1er semestre)', 'Perfil — vista general', 'Pre-inscripción 2027',
]);

it('renders user-supplied values as literal text, never as Markdown links', function (string $field, string $payload) {
    $school = School::factory()->create(['name' => 'Colegio Norte']);
    $user = User::factory()->forSchool($school)->teacher()->create(['name' => 'Ana Pérez']);
    $values = ['message' => 'No me deja guardar las notas', 'screen' => '/evaluaciones', 'screen_name' => 'Evaluaciones'];
    $values[$field] = $payload;

    $message = Tenancy::forSchool($school, fn () => SupportMessage::create([
        'user_id' => $user->id,
        'kind' => SupportMessage::KIND_ISSUE,
        'message' => $values['message'],
        'role' => 'teacher',
        'screen' => $values['screen'],
    ]));

    // screen_name is built directly here: the encoding must hold even if a
    // value ever got past validation.
    $mail = (new SupportMessageNotification($message, $values['screen_name']))->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();
    $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
    $squash = fn (string $value) => trim(preg_replace('/\s+/', ' ', $value));
    $visible = $squash(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    expect($html)->not->toContain('evil.example"')
        ->not->toContain('<img')
        ->not->toContain('<b>')
        // Shown as typed (Laravel's Markdown::render() itself drops a "\" before "[").
        ->and($visible)->toContain($squash(str_replace('\[', '[', $payload)))
        // The plain-text part shows the value as typed, without escapes.
        ->and($text)->toContain($payload);
})->with([
    'message: link' => ['message', 'Hola [Restablecer acceso](https://evil.example) gracias'],
    'message: backslash bypass' => ['message', 'Hola \\[Restablecer acceso](https://evil.example)'],
    'message: image' => ['message', 'Mirá ![logo](https://evil.example/x.png)'],
    'message: autolink' => ['message', 'Entrá a <https://evil.example>'],
    'message: html' => ['message', 'Texto <b>negrita</b>'],
    'message: reference link' => ['message', "[Restablecer acceso][1]\n\n[1]: https://evil.example"],
    'screen: link' => ['screen', '[Restablecer acceso](https://evil.example)'],
    'screen_name: link' => ['screen_name', '[Restablecer acceso](https://evil.example)'],
]);
