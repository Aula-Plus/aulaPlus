<?php

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\AlertRoutingSetting;
use App\Models\AlertRule;
use App\Models\Comment;
use App\Models\CommentCategory;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\SchoolTime;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3qkwpn — «Señal de dificultad»: one signal per category on every
// categorized observation, the trend marks count only improvement/difficulty,
// and a school-configured alert when difficulty signals pile up in one
// category.

beforeEach(function () {
    $this->school = School::factory()->create(['comment_trend_min_count' => 3, 'comment_trend_days' => 10]);
    $this->teacher = User::factory()->forSchool($this->school)->teacher()->create();
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    leadGroup($this->group, $this->teacher);
    $this->student = Student::factory()->create(['school_id' => $this->school->id]);
    $this->student->groups()->attach($this->group, ['school_year' => now()->year]);
    $this->psico = User::factory()->forSchool($this->school)->psychopedagogue()->create();
    $this->director = User::factory()->forSchool($this->school)->director()->create();

    $this->category = fn (string $name): int => CommentCategory::withoutGlobalScopes()
        ->where('school_id', $this->school->id)->where('name', $name)->value('id');

    // An observation on the student with the given signal per category name.
    $this->observe = function (array $signals, int $daysAgo = 1, ?User $author = null, array $state = []): Comment {
        $comment = Comment::factory()->forSubject($this->student)->create([
            'author_id' => ($author ?? $this->teacher)->id,
            'created_at' => now()->subDays($daysAgo),
            ...$state,
        ]);
        foreach ($signals as $name => $signal) {
            $comment->categories()->attach(($this->category)($name), ['signal' => $signal]);
        }

        return $comment;
    };

    $this->rule = fn (int $count, int $days) => Tenancy::forSchool($this->school, fn () => AlertRule::factory()->create([
        'school_id' => $this->school->id,
        'condition' => 'difficulty_signals',
        'threshold' => null,
        'consecutive_count' => null,
        'signal_count' => $count,
        'period_days' => $days,
    ]));

    $this->alerts = fn () => Alert::query()->withoutGlobalScopes()
        ->where('type', AlertType::DifficultySignal->value)->orderBy('id')->get();
});

function signalTracking(Student $student): array
{
    return test()->getJson("/api/v1/students/{$student->id}/tracking")->assertOk()->json('data');
}

// ── Validation ──────────────────────────────────────────────────────────────

it('requires one signal for every category of a student observation', function () {
    Sanctum::actingAs($this->teacher);
    $url = "/api/v1/students/{$this->student->id}/comments";
    $social = ($this->category)('Social');
    $academic = ($this->category)('Académico');

    // No signal at all.
    $this->postJson($url, ['content' => 'x', 'category_ids' => [$social]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors("category_signals.{$social}");

    // One of two missing.
    $this->postJson($url, [
        'content' => 'x',
        'category_ids' => [$social, $academic],
        'category_signals' => [$social => 'difficulty'],
    ])->assertUnprocessable()->assertJsonValidationErrors("category_signals.{$academic}");

    // Invalid value.
    $this->postJson($url, [
        'content' => 'x',
        'category_ids' => [$social],
        'category_signals' => [$social => 'worse'],
    ])->assertUnprocessable()->assertJsonValidationErrors("category_signals.{$social}");

    // A signal for a category the observation doesn't have.
    $this->postJson($url, [
        'content' => 'x',
        'category_ids' => [$social],
        'category_signals' => [$social => 'difficulty', $academic => 'improvement'],
    ])->assertUnprocessable()->assertJsonValidationErrors('category_signals');

    expect(Comment::withoutGlobalScopes()->count())->toBe(0);

    // One signal per category: stored per category and returned.
    $response = $this->postJson($url, [
        'content' => 'Discutió en el recreo, pero entregó la tarea',
        'category_ids' => [$social, $academic],
        'category_signals' => [$social => 'difficulty', $academic => 'improvement'],
    ])->assertCreated();
    expect(collect($response->json('data.categories'))->pluck('signal', 'name')->all())
        ->toEqual(['Social' => 'difficulty', 'Académico' => 'improvement']);

    // An observation without category needs no signal.
    $this->postJson($url, ['content' => 'sin categoría'])->assertCreated();
});

it('applies the same signal rule to group comments, which also take categories', function () {
    Sanctum::actingAs($this->teacher);
    $url = "/api/v1/groups/{$this->group->id}/comments";
    $social = ($this->category)('Social');

    $this->postJson($url, ['content' => 'x', 'category_ids' => [$social]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors("category_signals.{$social}");

    $this->postJson($url, ['content' => 'x', 'category_ids' => [$social], 'category_signals' => [$social => 'no_change']])
        ->assertCreated()
        ->assertJsonPath('data.categories.0.signal', 'no_change');
});

// ── Trend mark and private mark ─────────────────────────────────────────────

it('counts only improvement and difficulty signals in the trend mark, never no-change or legacy rows', function () {
    // Threshold 3 in 10 days. Social: 1 difficulty + 1 improvement + 1
    // no-change + 1 legacy (no signal) = only 2 that count.
    ($this->observe)(['Social' => 'difficulty']);
    ($this->observe)(['Social' => 'improvement'], 2);
    ($this->observe)(['Social' => 'no_change'], 3);
    $legacy = Comment::factory()->forSubject($this->student)->create(['author_id' => $this->teacher->id, 'created_at' => now()->subDays(4)]);
    $legacy->categories()->attach(($this->category)('Social'));
    // No-change in Social counts nowhere, even while the same observation's
    // difficulty in Académico counts there.
    ($this->observe)(['Social' => 'no_change', 'Académico' => 'difficulty'], 2);

    Sanctum::actingAs($this->psico);
    expect(signalTracking($this->student)['comment_trends'])->toBe([]);

    // A third counting one (improvement and difficulty count together for now).
    ($this->observe)(['Social' => 'improvement'], 5);
    $trends = signalTracking($this->student)['comment_trends'];
    expect($trends)->toHaveCount(1)
        ->and($trends[0])->toMatchArray(['category_name' => 'Social', 'count' => 3]);
});

it('counts only improvement and difficulty signals in the author\'s private mark', function () {
    $private = ['author_only' => true, 'visible_to' => null];
    ($this->observe)(['Social' => 'difficulty'], 1, $this->teacher, $private);
    ($this->observe)(['Social' => 'no_change'], 2, $this->teacher, $private);
    ($this->observe)(['Social' => 'no_change'], 3, $this->teacher, $private);
    $legacy = Comment::factory()->forSubject($this->student)->authorOnly()
        ->create(['author_id' => $this->teacher->id, 'created_at' => now()->subDays(2)]);
    $legacy->categories()->attach(($this->category)('Social'));

    Sanctum::actingAs($this->teacher);
    expect(signalTracking($this->student)['private_notes_signal'])->toBeFalse();

    ($this->observe)(['Social' => 'improvement'], 2, $this->teacher, $private);
    ($this->observe)(['Social' => 'difficulty'], 3, $this->teacher, $private);
    expect(signalTracking($this->student)['private_notes_signal'])->toBeTrue();
});

// ── Rule configuration ──────────────────────────────────────────────────────

it('lets direction and psychopedagogy configure the difficulty rule with no default values', function () {
    Sanctum::actingAs($this->psico);

    // Both numbers are required: Aula+ suggests none.
    $this->postJson('/api/v1/alert-rules', ['condition' => 'difficulty_signals'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['signal_count', 'period_days'])
        ->assertJsonMissingValidationErrors('threshold');
    $this->postJson('/api/v1/alert-rules', ['condition' => 'difficulty_signals', 'signal_count' => 3])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period_days');
    $this->postJson('/api/v1/alert-rules', ['condition' => 'difficulty_signals', 'period_days' => 30])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('signal_count');
    $this->postJson('/api/v1/alert-rules', ['condition' => 'difficulty_signals', 'signal_count' => 1, 'period_days' => 30])
        ->assertJsonValidationErrors('signal_count');

    $id = $this->postJson('/api/v1/alert-rules', [
        'condition' => 'difficulty_signals',
        'signal_count' => 3,
        'period_days' => 30,
        // Ignored by this condition: never stored.
        'threshold' => 5,
    ])->assertCreated()
        ->assertJsonPath('data.signal_count', 3)
        ->assertJsonPath('data.period_days', 30)
        ->assertJsonPath('data.threshold', null)
        ->assertJsonPath('data.subject_id', null)
        ->json('data.id');

    Sanctum::actingAs($this->director);
    $this->patchJson("/api/v1/alert-rules/{$id}", ['signal_count' => 4])->assertOk()->assertJsonPath('data.signal_count', 4);

    // Performance conditions still need their threshold.
    $this->postJson('/api/v1/alert-rules', ['condition' => 'consecutive_below', 'consecutive_count' => 3])
        ->assertJsonValidationErrors('threshold');
    $this->postJson('/api/v1/alert-rules', ['condition' => 'average_below', 'threshold' => 6, 'period_days' => 3])
        ->assertJsonValidationErrors('period_days');

    Sanctum::actingAs($this->teacher);
    $this->postJson('/api/v1/alert-rules', ['condition' => 'difficulty_signals', 'signal_count' => 3, 'period_days' => 30])
        ->assertForbidden();
    $this->patchJson("/api/v1/alert-rules/{$id}", ['active' => false])->assertForbidden();
});

it('routes the difficulty alert to psychopedagogy by default and never to teachers', function () {
    Sanctum::actingAs($this->director);

    $routing = collect($this->getJson('/api/v1/alert-settings')->assertOk()->json('data.routing'))->keyBy('type');
    expect($routing['difficulty_signal'])->toMatchArray([
        'recipients' => ['psychopedagogue'],
        'allowed_recipients' => ['psychopedagogue', 'director'],
        'is_default' => true,
    ]);

    $this->putJson('/api/v1/alert-routing/difficulty_signal', ['recipients' => ['teacher']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipients.0');
    $this->putJson('/api/v1/alert-routing/difficulty_signal', ['recipients' => ['psychopedagogue', 'teacher']])
        ->assertUnprocessable();
    $this->putJson('/api/v1/alert-routing/difficulty_signal', ['recipients' => []])->assertUnprocessable();
    $this->putJson('/api/v1/alert-routing/difficulty_signal', ['recipients' => ['psychopedagogue', 'director']])
        ->assertOk();

    // Even a stored row naming teachers (e.g. written by hand) is stripped
    // when the alert is generated.
    Tenancy::forSchool($this->school, fn () => AlertRoutingSetting::query()
        ->where('type', 'difficulty_signal')->update(['recipients' => json_encode(['teacher', 'director'])]));
    ($this->rule)(2, 30);
    ($this->observe)(['Social' => 'difficulty'], 2);
    ($this->observe)(['Social' => 'difficulty'], 1);

    $this->artisan('alerts:difficulty-signals')->assertSuccessful();

    $alert = ($this->alerts)()->sole();
    expect($alert->recipients)->toBe(['director']);

    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/students/{$this->student->id}/alerts")->assertOk()->assertJsonCount(0, 'data');

    // A snapshot naming teachers still never shows it to one (defence in depth).
    $alert->forceFill(['recipients' => ['teacher']])->saveQuietly();
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
});

it('does not let a difficulty alert be handed to a teacher', function () {
    ($this->rule)(2, 30);
    ($this->observe)(['Social' => 'difficulty'], 2);
    ($this->observe)(['Social' => 'difficulty'], 1);
    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    $alert = ($this->alerts)()->sole();

    Sanctum::actingAs($this->psico);
    $candidates = collect($this->getJson("/api/v1/alerts/{$alert->id}/handoff-candidates")->assertOk()->json('data'))->pluck('id');
    expect($candidates->all())->toBe([$this->director->id]);

    $due = now()->addWeek()->toDateString();
    $this->postJson("/api/v1/alerts/{$alert->id}/outcome", ['outcome' => 'handed_off', 'assignee_id' => $this->teacher->id, 'due_on' => $due])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assignee_id');
    $this->postJson("/api/v1/alerts/{$alert->id}/outcome", ['outcome' => 'handed_off', 'assignee_id' => $this->director->id, 'due_on' => $due])
        ->assertOk();
});

it('keeps an overdue handed-off difficulty alert away from teachers', function () {
    ($this->rule)(2, 30);
    ($this->observe)(['Social' => 'difficulty'], 2);
    ($this->observe)(['Social' => 'difficulty'], 1);
    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    $alert = ($this->alerts)()->sole();

    Sanctum::actingAs($this->psico);
    $this->postJson("/api/v1/alerts/{$alert->id}/outcome", [
        'outcome' => 'handed_off', 'assignee_id' => $this->director->id, 'due_on' => now()->addDay()->toDateString(),
    ])->assertOk();

    // A missed deadline marks the same alert (ClickUp 86e3jpzdp, 14/10): no
    // second alert is created that could reach anyone else.
    $this->travel(3)->days();
    $this->artisan('alerts:overdue')->assertSuccessful();

    expect($alert->fresh()->overdue_at)->not->toBeNull()
        ->and(($this->alerts)()->count())->toBe(1);

    // The teacher who teaches the student learns nothing about the case.
    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/students/{$this->student->id}/alerts")->assertOk()->assertJsonCount(0, 'data');
});

// ── Generation ──────────────────────────────────────────────────────────────

it('generates nothing while the school has no difficulty rule', function () {
    foreach ([1, 2, 3, 4] as $daysAgo) {
        ($this->observe)(['Social' => 'difficulty'], $daysAgo);
    }

    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    $this->artisan('alerts:performance')->assertSuccessful();

    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('raises the alert per category once N difficulty signals fall within D days', function () {
    ($this->rule)(3, 10);

    // Social: two inside the window, one outside → not yet.
    ($this->observe)(['Social' => 'difficulty'], 2);
    ($this->observe)(['Social' => 'difficulty'], 5);
    ($this->observe)(['Social' => 'difficulty'], 20);
    // Improvement and no-change never count toward this alert.
    ($this->observe)(['Social' => 'improvement'], 3);
    ($this->observe)(['Social' => 'no_change'], 3);
    // A difficulty in another category counts only there.
    ($this->observe)(['Académico' => 'difficulty', 'Social' => 'no_change'], 1);

    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)())->toHaveCount(0);

    ($this->observe)(['Social' => 'difficulty', 'Académico' => 'improvement'], 1);
    $this->artisan('alerts:difficulty-signals')->assertSuccessful();

    $alert = ($this->alerts)()->sole();
    expect($alert->comment_category_id)->toBe(($this->category)('Social'))
        ->and($alert->student_id)->toBe($this->student->id)
        ->and($alert->subject_id)->toBeNull()
        ->and($alert->description)->toBe('3 señales de dificultad en Social')
        ->and($alert->condition_met_on->toDateString())->toBe(now()->subDay()->setTimezone(SchoolTime::timezone())->toDateString())
        ->and($alert->recipients)->toBe(['psychopedagogue']);

    // Performance rules are not evaluated by this command, nor this rule by
    // the performance one.
    $this->artisan('alerts:performance')->assertSuccessful();
    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('counts reserved observations too (not «Solo quien escribe»), but the alert shows only the number and the category', function () {
    ($this->rule)(3, 30);
    Log::spy();

    $texts = [
        'Contenido reservado para psicopedagogía',
        'Nota privada de quien escribe',
        'Solo para algunas personas',
        'Visible para todos',
    ];
    ($this->observe)(['Emocional' => 'difficulty'], 4, $this->teacher, ['content' => $texts[0], 'visible_to' => Comment::SCOPE_PSYCHOPEDAGOGY_ONLY]);
    ($this->observe)(['Emocional' => 'difficulty'], 3, $this->teacher, ['content' => $texts[1], 'author_only' => true]);
    ($this->observe)(['Emocional' => 'difficulty'], 2, $this->psico, ['content' => $texts[2], 'visible_to_users' => ['director' => [$this->director->id]]]);
    ($this->observe)(['Emocional' => 'difficulty'], 1, $this->psico, ['content' => $texts[3]]);

    $this->artisan('alerts:difficulty-signals')->assertSuccessful();

    $alert = ($this->alerts)()->sole();
    // The «Solo quien escribe» note is left out: 3, not 4.
    expect($alert->description)->toBe('3 señales de dificultad en Emocional');

    Sanctum::actingAs($this->psico);
    $json = $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'difficulty_signal')
        ->assertJsonPath('data.0.comment_category_name', 'Emocional')
        ->getContent();

    $authors = [$this->teacher->name, $this->psico->name];
    foreach ([...$texts, ...$authors] as $leak) {
        expect($json)->not->toContain($leak);
    }

    // Never the observation content in the logs either.
    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($texts): bool {
        $flat = json_encode($context);
        foreach ($texts as $text) {
            if (str_contains($flat, $text)) {
                return false;
            }
        }

        return $message === 'status-change.alert.generated' && ! array_key_exists('description', $context);
    })->once();

    // Direction is not marked by default: it doesn't see the alert.
    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
});

it('never counts «Solo quien escribe» notes in the alert: they only feed their author\'s private mark', function () {
    ($this->rule)(2, 30);
    $private = ['author_only' => true, 'visible_to' => null];
    ($this->observe)(['Social' => 'difficulty'], 3, $this->teacher, $private);
    ($this->observe)(['Social' => 'difficulty'], 2, $this->teacher, $private);
    ($this->observe)(['Social' => 'difficulty'], 1, $this->teacher, $private);

    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)()->count())->toBe(0);

    // One more, readable by others, is still only one counted signal.
    ($this->observe)(['Social' => 'difficulty'], 1, $this->teacher);
    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)()->count())->toBe(0);
});

it('keeps one open alert per student and category and never re-raises it on the same evidence', function () {
    ($this->rule)(2, 30);
    ($this->rule)(3, 30);
    ($this->observe)(['Social' => 'difficulty'], 3);
    ($this->observe)(['Social' => 'difficulty'], 2);
    ($this->observe)(['Social' => 'difficulty'], 1);

    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)())->toHaveCount(1);

    // Resolved: re-running on the same signals raises nothing.
    ($this->alerts)()->sole()->forceFill(['resolved' => true, 'resolved_at' => now()])->saveQuietly();
    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)())->toHaveCount(1);

    // New evidence (a later signal) raises a new one.
    ($this->observe)(['Social' => 'difficulty'], 0);
    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)())->toHaveCount(2)
        ->and(($this->alerts)()->last()->description)->toBe('4 señales de dificultad en Social');
});

it('ignores group comments, deleted students and paused rules', function () {
    $rule = ($this->rule)(2, 30);

    $groupComment = fn () => Comment::factory()->forSubject($this->group)->create(['author_id' => $this->teacher->id])
        ->categories()->attach(($this->category)('Social'), ['signal' => 'difficulty']);
    $groupComment();
    $groupComment();

    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)())->toHaveCount(0);

    ($this->observe)(['Social' => 'difficulty'], 2);
    ($this->observe)(['Social' => 'difficulty'], 1);
    $rule->forceFill(['active' => false])->saveQuietly();
    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)())->toHaveCount(0);

    $rule->forceFill(['active' => true])->saveQuietly();
    Tenancy::forSchool($this->school, fn () => $this->student->delete());
    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)())->toHaveCount(0);
});

it('counts signals on the school\'s calendar days, not UTC ones', function () {
    // 09:00 in Montevideo (UTC-3) on 22/10.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
    ($this->rule)(2, 1); // 2 signals since yesterday (21/10, local).

    // 02:00 UTC on 21/10 is still 20/10 at 23:00 in Montevideo: outside.
    ($this->observe)(['Social' => 'difficulty'], 0, null, ['created_at' => CarbonImmutable::parse('2026-10-21 02:00:00', 'UTC')]);
    ($this->observe)(['Social' => 'difficulty'], 0, null, ['created_at' => CarbonImmutable::parse('2026-10-22 10:00:00', 'UTC')]);

    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)())->toHaveCount(0);

    // 02:00 UTC on 23/10 would be "tomorrow" in UTC terms, but it is 22/10 at
    // 23:00 in Montevideo — the day the condition is met.
    $this->travelTo(CarbonImmutable::parse('2026-10-23 02:30:00', 'UTC'));
    ($this->observe)(['Social' => 'difficulty'], 0, null, ['created_at' => CarbonImmutable::parse('2026-10-23 02:00:00', 'UTC')]);

    $this->artisan('alerts:difficulty-signals')->assertSuccessful();
    expect(($this->alerts)()->sole()->condition_met_on->toDateString())->toBe('2026-10-22');
});
