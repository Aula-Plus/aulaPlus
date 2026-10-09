<?php

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3jpzcv — "Alerta de desempeño bajo sostenido": Aula+ generates
// it from the condition the school configured, stores the subject, and it
// reaches first only the teacher of that subject in the student's group.

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->group = Group::factory()->create(['school_id' => $this->school->id]);
    $this->math = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Matemática']);
    $this->english = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Inglés']);

    $this->mathTeacher = User::factory()->forSchool($this->school)->teacher()->create();
    $this->englishTeacher = User::factory()->forSchool($this->school)->teacher()->create();
    leadGroup($this->group, $this->mathTeacher, $this->math);
    leadGroup($this->group, $this->englishTeacher, $this->english);

    $this->psychopedagogue = User::factory()->forSchool($this->school)->psychopedagogue()->create();
    $this->director = User::factory()->forSchool($this->school)->director()->create();

    $this->student = Student::factory()->create(['school_id' => $this->school->id]);
    $this->student->groups()->attach($this->group, ['school_year' => now()->year]);

    $this->scores = function (Subject $subject, array $scores, ?Student $student = null): void {
        $student ??= $this->student;
        Tenancy::forSchool($this->school, function () use ($subject, $scores, $student): void {
            foreach ($scores as $daysAgo => $score) {
                $assessment = Assessment::factory()->create([
                    'group_id' => $this->group->id,
                    'subject_id' => $subject->id,
                    'teacher_id' => $this->mathTeacher->id,
                    'administered_at' => now()->subDays($daysAgo)->toDateString(),
                ]);
                AssessmentResult::factory()->create([
                    'assessment_id' => $assessment->id,
                    'student_id' => $student->id,
                    'score' => $score,
                    'created_by_id' => $this->mathTeacher->id,
                ]);
            }
        });
    };

    $this->rule = fn (array $attributes = []) => Tenancy::forSchool(
        $this->school,
        fn () => AlertRule::factory()->create(['school_id' => $this->school->id, ...$attributes]),
    );
});

it('generates nothing when the school has not configured a condition', function () {
    ($this->scores)($this->math, [30 => 4, 20 => 3, 10 => 4]);

    $this->artisan('alerts:performance')->assertSuccessful();

    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('generates the alert with its subject when the last N scores are all below the threshold', function () {
    ($this->rule)();
    ($this->scores)($this->math, [30 => 4, 20 => 3, 10 => 4]);
    ($this->scores)($this->english, [25 => 8, 15 => 9, 5 => 7]);

    $this->artisan('alerts:performance')->assertSuccessful();

    $alerts = Alert::query()->withoutGlobalScopes()->get();
    expect($alerts)->toHaveCount(1);

    $alert = $alerts->first();
    expect($alert->type)->toBe(AlertType::Performance)
        ->and($alert->subject_id)->toBe($this->math->id)
        ->and($alert->student_id)->toBe($this->student->id)
        ->and($alert->recipients)->toBe(['teacher'])
        ->and($alert->condition_met_on->toDateString())->toBe(now()->subDays(10)->toDateString())
        ->and($alert->description)->toContain('3 notas seguidas por debajo de 5')
        ->and($alert->description)->toContain('Matemática')
        ->and($alert->description)->toContain('4, 3 y 4');
});

it('does not fire when a recent score breaks the streak', function () {
    ($this->rule)();
    ($this->scores)($this->math, [40 => 4, 30 => 3, 20 => 6, 10 => 4]);

    $this->artisan('alerts:performance')->assertSuccessful();

    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps a single open alert per student and subject across runs', function () {
    ($this->rule)();
    ($this->rule)(['consecutive_count' => 2, 'threshold' => 6]);
    ($this->scores)($this->math, [30 => 4, 20 => 3, 10 => 4]);

    $this->artisan('alerts:performance')->assertSuccessful();
    $this->artisan('alerts:performance')->assertSuccessful();

    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('does not re-raise a resolved alert on the same scores, only on new evidence', function () {
    ($this->rule)();
    ($this->scores)($this->math, [30 => 4, 20 => 3, 10 => 4]);

    $this->artisan('alerts:performance')->assertSuccessful();
    Alert::query()->withoutGlobalScopes()->update(['resolved' => true, 'resolved_at' => now()]);

    // Re-running with the same scores must not bring the closed alert back.
    $this->artisan('alerts:performance')->assertSuccessful();
    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(1);

    // A new low score is new evidence: a new alert is generated.
    ($this->scores)($this->math, [2 => 2]);
    $this->artisan('alerts:performance')->assertSuccessful();

    $alerts = Alert::query()->withoutGlobalScopes()->orderBy('id')->get();
    expect($alerts)->toHaveCount(2)
        ->and($alerts->last()->resolved)->toBeFalse()
        ->and($alerts->last()->condition_met_on->toDateString())->toBe(now()->subDays(2)->toDateString());
});

it('skips soft-deleted students', function () {
    ($this->rule)();
    ($this->scores)($this->math, [30 => 4, 20 => 3, 10 => 4]);
    $this->student->delete();

    $this->artisan('alerts:performance')->assertSuccessful();

    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('evaluates each school only against its own conditions', function () {
    // School A (beforeEach) has low scores but no rule; school B has a rule
    // but its student is doing fine. Nothing must cross between them.
    ($this->scores)($this->math, [30 => 4, 20 => 3, 10 => 4]);

    $otherSchool = School::factory()->create();
    $otherGroup = Group::factory()->create(['school_id' => $otherSchool->id]);
    $otherSubject = Subject::factory()->create(['school_id' => $otherSchool->id]);
    $otherTeacher = User::factory()->forSchool($otherSchool)->teacher()->create();
    $otherStudent = Student::factory()->create(['school_id' => $otherSchool->id]);
    $otherStudent->groups()->attach($otherGroup, ['school_year' => now()->year]);
    Tenancy::forSchool($otherSchool, function () use ($otherSchool, $otherGroup, $otherSubject, $otherTeacher, $otherStudent): void {
        AlertRule::factory()->create(['school_id' => $otherSchool->id]);
        foreach ([30 => 8, 20 => 9, 10 => 7] as $daysAgo => $score) {
            $assessment = Assessment::factory()->create([
                'group_id' => $otherGroup->id,
                'subject_id' => $otherSubject->id,
                'teacher_id' => $otherTeacher->id,
                'administered_at' => now()->subDays($daysAgo)->toDateString(),
            ]);
            AssessmentResult::factory()->create([
                'assessment_id' => $assessment->id,
                'student_id' => $otherStudent->id,
                'score' => $score,
                'created_by_id' => $otherTeacher->id,
            ]);
        }
    });

    $this->artisan('alerts:performance')->assertSuccessful();
    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(0);

    // Once school A configures its own rule, its alert is created in school A.
    ($this->rule)();
    $this->artisan('alerts:performance')->assertSuccessful();

    $alert = Alert::query()->withoutGlobalScopes()->sole();
    expect($alert->school_id)->toBe($this->school->id)
        ->and($alert->student_id)->toBe($this->student->id);
});

it('supports the period-average condition', function () {
    ($this->rule)(['condition' => 'average_below', 'threshold' => 6, 'consecutive_count' => null, 'period_days' => 60]);
    // The old 9 falls outside the 60-day window, so the average is 5.
    ($this->scores)($this->math, [200 => 9, 40 => 5, 20 => 4, 10 => 6]);

    $this->artisan('alerts:performance')->assertSuccessful();

    $alert = Alert::query()->withoutGlobalScopes()->sole();
    expect($alert->description)->toContain('promedio 5 en 3 notas');
});

it('limits a subject-specific rule to that subject', function () {
    ($this->rule)(['subject_id' => $this->english->id]);
    ($this->scores)($this->math, [30 => 4, 20 => 3, 10 => 4]);

    $this->artisan('alerts:performance')->assertSuccessful();

    expect(Alert::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('shows a new alert only to the teacher of that subject in the student group', function () {
    ($this->rule)();
    ($this->scores)($this->math, [30 => 4, 20 => 3, 10 => 4]);
    $this->artisan('alerts:performance')->assertSuccessful();

    Sanctum::actingAs($this->mathTeacher);
    $this->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject_name', 'Matemática')
        ->assertJsonPath('data.0.student_name', $this->student->full_name);
    $this->getJson("/api/v1/students/{$this->student->id}/tracking")
        ->assertOk()
        ->assertJsonPath('data.open_alerts_count', 1)
        ->assertJsonCount(1, 'data.alerts');

    foreach ([$this->englishTeacher, $this->psychopedagogue, $this->director] as $other) {
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
    }

    Sanctum::actingAs($this->director);
    $this->getJson("/api/v1/students/{$this->student->id}/tracking")
        ->assertOk()
        ->assertJsonPath('data.open_alerts_count', 0);
    $this->getJson("/api/v1/groups/{$this->group->id}/alerts")->assertOk()->assertJsonCount(0, 'data');

    $alert = Alert::query()->withoutGlobalScopes()->sole();
    $this->postJson("/api/v1/alerts/{$alert->id}/resolve")->assertForbidden();
});

it('routes new alerts to psychopedagogy too when the school changes the setting', function () {
    ($this->rule)();
    ($this->scores)($this->math, [30 => 4, 20 => 3, 10 => 4]);

    Sanctum::actingAs($this->director);
    $this->putJson('/api/v1/alert-routing/performance', ['recipients' => ['teacher', 'psychopedagogue']])
        ->assertOk();

    $this->artisan('alerts:performance')->assertSuccessful();

    Sanctum::actingAs($this->psychopedagogue);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($this->director);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps legacy alerts visible to school-wide roles only', function () {
    Tenancy::forSchool($this->school, fn () => Alert::factory()->create(['student_id' => $this->student->id]));

    Sanctum::actingAs($this->psychopedagogue);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($this->mathTeacher);
    $this->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');
});

it('lets direction and psychopedagogy manage the conditions, not teachers', function () {
    Sanctum::actingAs($this->psychopedagogue);
    $id = $this->postJson('/api/v1/alert-rules', [
        'condition' => 'consecutive_below',
        'threshold' => 5,
        'consecutive_count' => 3,
        'subject_id' => $this->math->id,
    ])->assertCreated()->json('data.id');

    $this->getJson('/api/v1/alert-settings')
        ->assertOk()
        ->assertJsonCount(1, 'data.rules')
        ->assertJsonPath('data.routing.0.type', 'performance')
        ->assertJsonPath('data.routing.0.recipients', ['teacher'])
        ->assertJsonPath('data.routing.0.is_default', true);

    Sanctum::actingAs($this->director);
    $this->patchJson("/api/v1/alert-rules/{$id}", [
        'condition' => 'average_below',
        'period_days' => 90,
        'threshold' => 6,
    ])->assertOk()
        ->assertJsonPath('data.condition', 'average_below')
        ->assertJsonPath('data.consecutive_count', null)
        ->assertJsonPath('data.period_days', 90);

    Sanctum::actingAs($this->mathTeacher);
    $this->getJson('/api/v1/alert-settings')->assertForbidden();
    $this->postJson('/api/v1/alert-rules', ['condition' => 'consecutive_below', 'threshold' => 5, 'consecutive_count' => 3])
        ->assertForbidden();
    $this->patchJson("/api/v1/alert-rules/{$id}", ['active' => false])->assertForbidden();
    $this->deleteJson("/api/v1/alert-rules/{$id}")->assertForbidden();
    $this->putJson('/api/v1/alert-routing/performance', ['recipients' => ['director']])->assertForbidden();

    Sanctum::actingAs($this->director);
    $this->deleteJson("/api/v1/alert-rules/{$id}")->assertNoContent();
});

it('validates the parameter each condition needs and the recipients', function () {
    Sanctum::actingAs($this->director);

    $this->postJson('/api/v1/alert-rules', ['condition' => 'consecutive_below', 'threshold' => 5])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('consecutive_count');
    $this->postJson('/api/v1/alert-rules', ['condition' => 'average_below', 'threshold' => 6])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period_days');
    $this->postJson('/api/v1/alert-rules', ['condition' => 'magic', 'threshold' => 6])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('condition');
    $this->putJson('/api/v1/alert-routing/performance', ['recipients' => []])
        ->assertUnprocessable();
    $this->putJson('/api/v1/alert-routing/performance', ['recipients' => ['parents']])
        ->assertUnprocessable();
    $this->putJson('/api/v1/alert-routing/unknown', ['recipients' => ['teacher']])
        ->assertNotFound();
});

it('isolates alert settings between schools', function () {
    $otherSchool = School::factory()->create();
    $otherDirector = User::factory()->forSchool($otherSchool)->director()->create();
    $rule = ($this->rule)();
    $foreignSubject = Subject::factory()->create(['school_id' => $otherSchool->id]);

    Sanctum::actingAs($otherDirector);
    $this->getJson('/api/v1/alert-settings')->assertOk()->assertJsonCount(0, 'data.rules');
    $this->patchJson("/api/v1/alert-rules/{$rule->id}", ['active' => false])->assertNotFound();
    $this->deleteJson("/api/v1/alert-rules/{$rule->id}")->assertNotFound();

    Sanctum::actingAs($this->director);
    $this->postJson('/api/v1/alert-rules', [
        'condition' => 'consecutive_below', 'threshold' => 5, 'consecutive_count' => 3,
        'subject_id' => $foreignSubject->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
});
