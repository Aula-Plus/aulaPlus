<?php

use App\Actions\AI\BuildAccommodationInput;
use App\Actions\AI\BuildProposalContext;
use App\Enums\AIInputMarkResolution;
use App\Enums\AIProposalType;
use App\Enums\AlertOutcome;
use App\Enums\AlertType;
use App\Models\Accommodation;
use App\Models\AIInputMark;
use App\Models\AIProposal;
use App\Models\Alert;
use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\AIInputMarkNotification;
use App\Support\Tenancy;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * «Ver lo que recibiría la IA» and «La IA lo entendió mal» (ClickUp
 * 1dfd3mc2wx2; documento vivo screen 03, P03-AJU-03 / P03-ACT-53).
 */
function aiInputScenario(): array
{
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Laura Ferrando']);
    $director = User::factory()->forSchool($school)->director()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create([
        'school_id' => $school->id,
        'name' => '2°EMS-A Distintivo',
        'education_level_id' => EducationLevel::query()->where('key', 'lower_secondary')->value('id'),
    ]);
    $math = leadGroup($group, $teacher, Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']));
    $students = Student::factory()->count(2)->sequence(
        ['full_name' => 'Valentina Rocamora'],
        ['full_name' => 'Bruno Echeverri'],
    )->create(['school_id' => $school->id]);

    foreach ($students as $student) {
        $group->students()->attach($student, ['school_year' => now()->year]);
    }

    $make = fn (Student $student, string $type, array $attributes = []) => Accommodation::factory()->create([
        'student_id' => $student->id,
        'type' => $type,
        'category' => 'access',
        'focus_area' => 'diagnóstico reservado',
        'description' => 'detalle clínico reservado',
        'starts_on' => now()->subMonth()->toDateString(),
        ...$attributes,
    ]);

    $extended = $make($students[0], 'Dar 15 minutos más en escritos');
    $make($students[1], 'Dar 15 minutos más en escritos');
    $readAloud = $make($students[1], 'Leer las consignas en voz baja');
    $readAloud->subjects()->attach($math);
    $make($students[0], 'Propuesta sin confirmar', ['review_status' => 'pending']);

    return compact('school', 'psychopedagogue', 'director', 'teacher', 'group', 'math', 'students', 'extended', 'readAloud', 'make');
}

function aiInputLines(array $scenario, User $as, array $query = []): array
{
    Sanctum::actingAs($as);

    return test()->getJson('/api/v1/groups/'.$scenario['group']->id.'/ai-input?'.http_build_query($query))
        ->assertOk()
        ->json('data.lines');
}

function aiInputKey(array $lines, string $action): string
{
    return collect($lines)->firstWhere('action', $action)['key'];
}

/** What the AI receives for a request of psychopedagogy without subject. */
function aiReceived(array $scenario): array
{
    $proposal = AIProposal::factory()->create([
        'group_id' => $scenario['group']->id,
        'requested_by_id' => $scenario['psychopedagogue']->id,
        'type' => AIProposalType::ClassSession,
        'input_parameters' => ['focus' => 'fracciones'],
    ]);

    return Tenancy::forSchool($scenario['school'], fn () => (new BuildProposalContext)($proposal))['accommodation_input'];
}

function markLine(array $scenario, string $key, array $extra = []): AIInputMark
{
    Sanctum::actingAs($scenario['psychopedagogue']);
    $id = test()->postJson('/api/v1/groups/'.$scenario['group']->id.'/ai-input/marks', ['line_key' => $key, ...$extra])
        ->assertCreated()
        ->json('data.id');

    return AIInputMark::query()->withoutGlobalScopes()->findOrFail($id);
}

it('shows psychopedagogy exactly the lines the AI would receive, with their origin', function () {
    $scenario = aiInputScenario();

    $lines = aiInputLines($scenario, $scenario['psychopedagogue']);

    expect(collect($lines)->pluck('action')->all())->toBe(['Dar 15 minutos más en escritos', 'Leer las consignas en voz baja'])
        ->and(collect($lines)->map(fn ($line) => collect($line)->only(['action', 'category', 'subjects', 'assessment_types'])->all())->all())
        ->toBe(aiReceived($scenario))
        ->and($lines[0]['sent'])->toBeTrue()
        ->and($lines[0]['mark'])->toBeNull()
        ->and(collect($lines[0]['origins'])->pluck('student_name')->sort()->values()->all())->toBe(['Bruno Echeverri', 'Valentina Rocamora']);
});

it('cuts the view to the chosen subject, which is optional', function () {
    $scenario = aiInputScenario();
    $science = Subject::factory()->create(['school_id' => $scenario['school']->id]);

    expect(collect(aiInputLines($scenario, $scenario['psychopedagogue'], ['subject_id' => $science->id]))->pluck('action')->all())
        ->toBe(['Dar 15 minutos más en escritos'])
        ->and(collect(aiInputLines($scenario, $scenario['psychopedagogue'], ['subject_id' => $scenario['math']->id]))->pluck('action')->all())
        ->toBe(['Dar 15 minutos más en escritos', 'Leer las consignas en voz baja']);
});

it('lets direction read the view but not mark, and keeps teachers out', function () {
    Notification::fake();
    $scenario = aiInputScenario();

    Sanctum::actingAs($scenario['director']);
    $response = $this->getJson('/api/v1/groups/'.$scenario['group']->id.'/ai-input')->assertOk();
    expect($response->json('data.can.mark'))->toBeFalse();
    $key = aiInputKey($response->json('data.lines'), 'Dar 15 minutos más en escritos');

    $this->postJson('/api/v1/groups/'.$scenario['group']->id.'/ai-input/marks', ['line_key' => $key])->assertForbidden();

    Sanctum::actingAs($scenario['teacher']);
    $this->getJson('/api/v1/groups/'.$scenario['group']->id.'/ai-input')->assertForbidden();
    $this->postJson('/api/v1/groups/'.$scenario['group']->id.'/ai-input/marks', ['line_key' => $key])->assertForbidden();

    expect(AIInputMark::query()->withoutGlobalScopes()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('isolates tenants', function () {
    $scenario = aiInputScenario();
    $other = School::factory()->create();
    $outsider = User::factory()->forSchool($other)->psychopedagogue()->create();
    $key = aiInputKey(aiInputLines($scenario, $scenario['psychopedagogue']), 'Dar 15 minutos más en escritos');
    $mark = markLine($scenario, $key);

    Sanctum::actingAs($outsider);
    $this->getJson('/api/v1/groups/'.$scenario['group']->id.'/ai-input')->assertNotFound();
    $this->postJson('/api/v1/groups/'.$scenario['group']->id.'/ai-input/marks', ['line_key' => $key])->assertNotFound();
    $this->postJson("/api/v1/ai-input-marks/{$mark->id}/adjustment-ok")->assertNotFound();

    expect($mark->fresh()->resolution)->toBeNull();
});

it('withholds a marked line from the AI while the mark is pending and tells the team, minimally', function () {
    Notification::fake();
    config(['services.support.notify_to' => ['equipo@aulaplus.test']]);
    $scenario = aiInputScenario();
    $key = aiInputKey(aiInputLines($scenario, $scenario['psychopedagogue']), 'Dar 15 minutos más en escritos');

    $mark = markLine($scenario, $key, ['subject_id' => $scenario['math']->id, 'comment' => 'Lo tomó como tiempo para todo el curso']);

    expect($mark->line['action'])->toBe('Dar 15 minutos más en escritos')
        ->and($mark->category?->value)->toBe('access')
        ->and($mark->marked_by_id)->toBe($scenario['psychopedagogue']->id)
        ->and(collect(aiReceived($scenario))->pluck('action')->all())->toBe(['Leer las consignas en voz baja']);

    $lines = aiInputLines($scenario, $scenario['psychopedagogue']);
    $marked = collect($lines)->firstWhere('key', $key);
    expect($marked['sent'])->toBeFalse()
        ->and($marked['mark']['comment'])->toBe('Lo tomó como tiempo para todo el curso');

    Notification::assertSentOnDemand(AIInputMarkNotification::class, function (AIInputMarkNotification $notification, $channels, AnonymousNotifiable $notifiable) {
        $mail = $notification->toMail($notifiable);
        $text = html_entity_decode(implode("\n", array_map('strval', $mail->introLines)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        expect($notifiable->routes['mail'])->toBe(['equipo@aulaplus.test'])
            ->and($mail->subject)->toBe('[Aula+] La IA lo entendió mal')
            ->and($text)->toContain('"action":"Dar 15 minutos más en escritos"')
            ->and($text)->toContain('Tipo de ajuste de origen: Acceso')
            ->and($text)->toContain('Materia: Matemática')
            ->and($text)->toContain('Lo tomó como tiempo para todo el curso')
            ->and($text)->toContain('Nivel del grupo: Ciclo Básico')
            // Nothing that identifies the group or a student: no group
            // name, no names, no who marked it.
            ->and($text)->not->toContain('Distintivo')
            ->and($text)->not->toContain('2°EMS-A')
            ->and($text)->not->toContain('Valentina')
            ->and($text)->not->toContain('Bruno')
            ->and($text)->not->toContain('Laura Ferrando')
            ->and($text)->not->toContain('reservado');

        return true;
    });
});

it('rejects a second mark on the same line, an unknown line and a comment with a student name', function () {
    Notification::fake();
    $scenario = aiInputScenario();
    $key = aiInputKey(aiInputLines($scenario, $scenario['psychopedagogue']), 'Dar 15 minutos más en escritos');
    markLine($scenario, $key);
    $url = '/api/v1/groups/'.$scenario['group']->id.'/ai-input/marks';

    $this->postJson($url, ['line_key' => $key])->assertUnprocessable()->assertJsonValidationErrors('line_key');
    $this->postJson($url, ['line_key' => str_repeat('a', 64)])->assertUnprocessable()->assertJsonValidationErrors('line_key');
    $this->postJson($url, ['line_key' => 'not-a-key'])->assertUnprocessable()->assertJsonValidationErrors('line_key');

    $other = aiInputKey(aiInputLines($scenario, $scenario['psychopedagogue']), 'Leer las consignas en voz baja');
    $this->postJson($url, ['line_key' => $other, 'comment' => 'Valentina no lo necesita'])
        ->assertUnprocessable()->assertJsonValidationErrors('comment');
    $this->postJson($url, ['line_key' => $other, 'comment' => str_repeat('a', 501)])
        ->assertUnprocessable()->assertJsonValidationErrors('comment');

    expect(AIInputMark::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('«El ajuste está bien» sends the line again, closes the mark, leaves no alert and still tells the team', function () {
    Notification::fake();
    config(['services.support.notify_to' => ['equipo@aulaplus.test']]);
    $scenario = aiInputScenario();
    $key = aiInputKey(aiInputLines($scenario, $scenario['psychopedagogue']), 'Dar 15 minutos más en escritos');
    $mark = markLine($scenario, $key);

    Sanctum::actingAs($scenario['director']);
    $this->postJson("/api/v1/ai-input-marks/{$mark->id}/adjustment-ok")->assertForbidden();

    Sanctum::actingAs($scenario['psychopedagogue']);
    $this->postJson("/api/v1/ai-input-marks/{$mark->id}/adjustment-ok", ['comment' => 'Está bien redactado'])
        ->assertOk()
        ->assertJsonPath('data.resolution', 'adjustment_ok');
    $this->postJson("/api/v1/ai-input-marks/{$mark->id}/adjustment-ok")->assertUnprocessable();

    $fresh = $mark->fresh();
    expect($fresh->resolution)->toBe(AIInputMarkResolution::AdjustmentOk)
        ->and($fresh->resolved_by_id)->toBe($scenario['psychopedagogue']->id)
        ->and($scenario['extended']->fresh()->type)->toBe('Dar 15 minutos más en escritos')
        ->and(collect(aiReceived($scenario))->pluck('action')->all())->toContain('Dar 15 minutos más en escritos');

    $this->travel(8)->days();
    Tenancy::forSchool($scenario['school'], fn () => $this->artisan('alerts:ai-input-marks')->assertSuccessful());
    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(0);

    Notification::assertSentOnDemandTimes(AIInputMarkNotification::class, 2);
    Notification::assertSentOnDemand(AIInputMarkNotification::class, function (AIInputMarkNotification $notification, $channels, $notifiable) {
        $mail = $notification->toMail($notifiable);

        return $mail->subject === '[Aula+] El ajuste está bien'
            && str_contains(html_entity_decode(implode("\n", array_map('strval', $mail->introLines)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'Está bien redactado');
    });
});

it('closes the mark when the origin accommodation changes', function () {
    Notification::fake();
    $scenario = aiInputScenario();
    $key = aiInputKey(aiInputLines($scenario, $scenario['psychopedagogue']), 'Leer las consignas en voz baja');
    $mark = markLine($scenario, $key);

    $scenario['readAloud']->update(['type' => 'Leer las consignas en voz alta, de a una']);

    $lines = aiInputLines($scenario, $scenario['psychopedagogue']);

    expect($mark->fresh()->resolution)->toBe(AIInputMarkResolution::AdjustmentChanged)
        ->and(collect($lines)->pluck('sent')->unique()->all())->toBe([true])
        ->and(collect(aiReceived($scenario))->pluck('action')->all())->toContain('Leer las consignas en voz alta, de a una');
});

it('raises one alert a week later for whoever marked it, with the usual ways out, and closes it with the mark', function () {
    Notification::fake();
    $scenario = aiInputScenario();
    $key = aiInputKey(aiInputLines($scenario, $scenario['psychopedagogue']), 'Dar 15 minutos más en escritos');
    $mark = markLine($scenario, $key, ['comment' => 'comentario reservado']);
    $colleague = User::factory()->forSchool($scenario['school'])->psychopedagogue()->create();

    $this->travel(6)->days();
    $this->artisan('alerts:ai-input-marks')->assertSuccessful();
    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->travel(2)->days();
    $this->artisan('alerts:ai-input-marks')->assertSuccessful();
    $this->artisan('alerts:ai-input-marks')->assertSuccessful();

    $alert = Alert::query()->withoutGlobalScopes()->sole();
    expect($alert->type)->toBe(AlertType::AIInputMark)
        ->and($alert->student_id)->toBeNull()
        ->and($alert->ai_input_mark_id)->toBe($mark->id)
        ->and($alert->description)->not->toContain('comentario reservado')
        ->and($alert->description)->not->toContain('Dar 15 minutos');

    // It reaches the person who marked it, not other psychopedagogues,
    // direction or teachers.
    Sanctum::actingAs($colleague);
    expect($this->getJson('/api/v1/alerts')->assertOk()->json('data'))->toBe([]);
    Sanctum::actingAs($scenario['director']);
    expect($this->getJson('/api/v1/alerts')->assertOk()->json('data'))->toBe([]);
    Sanctum::actingAs($scenario['teacher']);
    expect($this->getJson('/api/v1/alerts')->assertOk()->json('data'))->toBe([]);

    Sanctum::actingAs($scenario['psychopedagogue']);
    $listed = $this->getJson('/api/v1/alerts')->assertOk()->json('data');
    expect($listed)->toHaveCount(1)
        ->and($listed[0]['ai_input_mark']['group_id'])->toBe($scenario['group']->id)
        ->and($listed[0]['ai_input_mark']['action'])->toBe('Dar 15 minutos más en escritos')
        ->and($listed[0]['can']['act'])->toBeTrue()
        ->and($listed[0]['can']['link_follow_up'])->toBeFalse();

    // The handoff picker offers psychopedagogy/direction, never a teacher.
    $candidates = collect($this->getJson("/api/v1/alerts/{$alert->id}/handoff-candidates")->assertOk()->json('data'))->pluck('id');
    expect($candidates->all())->toContain($colleague->id)
        ->toContain($scenario['director']->id)
        ->not->toContain($scenario['teacher']->id);

    $this->postJson("/api/v1/alerts/{$alert->id}/outcome", [
        'outcome' => AlertOutcome::Observing->value,
        'due_on' => now()->addWeek()->toDateString(),
    ])->assertOk();

    // Changing the accommodation closes the mark and its alert.
    $scenario['extended']->update(['active' => false]);
    Accommodation::query()->withoutGlobalScopes()->where('type', 'Dar 15 minutos más en escritos')->update(['active' => false]);
    $this->getJson('/api/v1/groups/'.$scenario['group']->id.'/ai-input')->assertOk();

    expect($mark->fresh()->resolution)->toBe(AIInputMarkResolution::AdjustmentChanged)
        ->and($alert->fresh()->resolved)->toBeTrue();
});

it('keeps the input identical to before when no line is marked', function () {
    $scenario = aiInputScenario();

    $lines = Tenancy::forSchool($scenario['school'], fn () => (new BuildAccommodationInput)(
        $scenario['group'],
        fn ($query) => $query->effective()->started(),
    ));

    expect($lines)->toBe([
        ['action' => 'Dar 15 minutos más en escritos', 'category' => 'access', 'subjects' => 'all', 'assessment_types' => 'all'],
        ['action' => 'Leer las consignas en voz baja', 'category' => 'access', 'subjects' => ['Matemática'], 'assessment_types' => 'all'],
    ]);
});
