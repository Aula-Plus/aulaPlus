<?php

use App\Models\OnboardingLogEntry;
use App\Models\School;
use App\Models\SupportMessage;
use App\Models\User;
use App\Notifications\SupportMessageNotification;
use App\Support\Tenancy;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

// Screen 29 «Puesta en marcha» (ClickUp 1dfd3mc4bh8): the Aula+ team loads the
// record with app:onboarding-* commands; Dirección reads it and asks for a
// regime correction through «Ayuda y sugerencias», at top urgency.

beforeEach(function () {
    $this->school = School::factory()->create(['name' => 'Colegio Modelo']);
    $this->director = User::factory()->forSchool($this->school)->director()->create();
    $this->teacher = User::factory()->forSchool($this->school)->teacher()->create();
    $this->psycho = User::factory()->forSchool($this->school)->psychopedagogue()->create();
});

it('lets the Aula+ team load the record by command and Dirección read it', function () {
    $id = $this->school->id;
    $this->artisan('app:onboarding-status', [
        'school' => $id, '--import-completed' => '2026-02-28', '--regime-configured' => '2026-02-20',
        '--opened' => '2026-03-02', '--contact-name' => 'Tiago', '--contact-channel' => 'Por mail',
    ])->assertSuccessful();
    $this->artisan('app:onboarding-regime', ['school' => $id, 'level' => 'primary', 'regime' => 'enabled', '--note' => 'Sigue el programa nacional'])->assertSuccessful();
    $this->artisan('app:onboarding-regime', ['school' => $id, 'level' => 'initial', 'regime' => 'authorized', '--note' => 'Programa propio'])->assertSuccessful();
    $this->artisan('app:onboarding-source', ['school' => $id, 'source' => 'Sistema de gestión del colegio', 'content' => 'Alumnos, grupos, docentes y materias', '--format' => 'CSV'])->assertSuccessful();
    $this->artisan('app:onboarding-log', ['school' => $id, 'date' => '2026-02-27', 'step' => 'Validación conjunta: el colegio revisó una muestra de 20 alumnos'])->assertSuccessful();

    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/onboarding')->assertOk()
        ->assertJsonPath('data.import_completed_on', '2026-02-28')
        ->assertJsonPath('data.opened_on', '2026-03-02')
        ->assertJsonPath('data.offering_confirmed_on', null)
        ->assertJsonPath('data.contact.name', 'Tiago')
        ->assertJsonPath('data.regimes.0.level_key', 'initial')
        ->assertJsonPath('data.regimes.0.regime', 'authorized')
        ->assertJsonPath('data.regimes.1.regime', 'enabled')
        // Ciclo Básico and Bachillerato read as «habilitado» even before loading.
        ->assertJsonPath('data.regimes.2.regime', 'enabled')
        ->assertJsonPath('data.regimes.2.note', 'Fijo: todos los colegios privados lo están')
        ->assertJsonPath('data.sources.0.format', 'CSV')
        ->assertJsonPath('data.sources.0.status', 'done')
        ->assertJsonPath('data.log.0.happened_on', '2026-02-27');
});

it('rejects bad dates, levels and regimes in the commands', function () {
    $id = $this->school->id;
    $this->artisan('app:onboarding-status', ['school' => $id, '--opened' => '02/03/2026'])->assertFailed();
    $this->artisan('app:onboarding-regime', ['school' => $id, 'level' => 'kinder', 'regime' => 'enabled'])->assertFailed();
    $this->artisan('app:onboarding-regime', ['school' => $id, 'level' => 'primary', 'regime' => 'maybe'])->assertFailed();
    $this->artisan('app:onboarding-log', ['school' => 999999, 'date' => '2026-02-27', 'step' => 'x'])->assertFailed();
});

it('removes a log step only within its school', function () {
    $other = School::factory()->create();
    $this->artisan('app:onboarding-log', ['school' => $other->id, 'date' => '2026-02-27', 'step' => 'Paso'])->assertSuccessful();
    $entryId = Tenancy::forSchool($other, fn () => OnboardingLogEntry::query()->value('id'));

    $this->artisan('app:onboarding-log', ['school' => $this->school->id, '--remove' => $entryId])->assertFailed();
    $this->artisan('app:onboarding-log', ['school' => $other->id, '--remove' => $entryId])->assertSuccessful();
});

it('is only for Dirección, and only its own school', function () {
    $other = School::factory()->create();
    $this->artisan('app:onboarding-status', ['school' => $other->id, '--opened' => '2026-03-02'])->assertSuccessful();

    foreach ([$this->teacher, $this->psycho] as $user) {
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/onboarding')->assertForbidden();
        $this->postJson('/api/v1/onboarding/regime-correction', ['message' => 'Primaria es autorizada'])->assertForbidden();
    }

    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/onboarding')->assertOk()->assertJsonPath('data.opened_on', null);
});

it('sends a regime correction to support at top urgency and offers the configured channels', function () {
    Notification::fake();
    config([
        'services.support.notify_to' => ['soporte@example.test'],
        'services.support.whatsapp' => '+598 99 000 000',
        'services.support.contact_email' => 'ayuda@example.test',
    ]);
    Sanctum::actingAs($this->director);

    $this->postJson('/api/v1/onboarding/regime-correction', ['message' => ''])->assertUnprocessable();
    $this->postJson('/api/v1/onboarding/regime-correction', ['message' => 'Primaria es autorizada, no habilitada.'])
        ->assertCreated()
        ->assertJsonPath('data.support_channels.whatsapp', '+598 99 000 000')
        ->assertJsonPath('data.support_channels.email', 'ayuda@example.test');

    $message = SupportMessage::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($message->kind)->toBe(SupportMessage::KIND_REGIME_CORRECTION)
        ->and($message->school_id)->toBe($this->school->id);

    Notification::assertSentTo(new AnonymousNotifiable, SupportMessageNotification::class, function ($notification, $channels, $notifiable) {
        $mail = $notification->toMail($notifiable);

        return $mail->subject === '[Aula+] URGENTE · Corrección de régimen' && $mail->priority === 1;
    });
});

it('does not offer channels that are not configured', function () {
    config(['services.support.whatsapp' => null, 'services.support.contact_email' => null]);
    Sanctum::actingAs($this->director);

    $this->getJson('/api/v1/onboarding')->assertOk()
        ->assertJsonPath('data.support_channels', ['whatsapp' => null, 'email' => null]);
});

it('keeps the regime correction out of the generic support form', function () {
    Sanctum::actingAs($this->director);

    $this->postJson('/api/v1/support-messages', ['kind' => 'regime_correction', 'message' => 'Algo no coincide'])
        ->assertUnprocessable();
});
