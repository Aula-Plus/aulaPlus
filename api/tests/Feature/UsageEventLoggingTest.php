<?php

use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\School;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

// docs/prompts/04-seguimiento-institucional.md §5
it('logs a login usage event on successful authentication', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();

    // A stateful Referer triggers Sanctum's session middleware on the api group.
    $this->withHeader('Referer', config('app.url'))
        ->postJson('/api/login', ['email' => $teacher->email, 'password' => 'password'])
        ->assertOk();

    expect(UsageEvent::query()->where('user_id', $teacher->id)->where('event_type', 'login')->exists())->toBeTrue();
});

it('logs a usage event when a teacher creates an annual plan, class session or assessment', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    Tenancy::useSchool($school);
    $annualPlan = AnnualPlan::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id]);
    $classSession = ClassSession::factory()->create(['group_id' => $group->id]);
    $assessment = Assessment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id]);
    Tenancy::forget();

    expect(UsageEvent::query()->where('event_type', 'annual_plan.created')->where('metadata->id', $annualPlan->id)->exists())->toBeTrue()
        ->and(UsageEvent::query()->where('event_type', 'class_session.created')->where('metadata->id', $classSession->id)->exists())->toBeTrue()
        ->and(UsageEvent::query()->where('event_type', 'assessment.created')->where('metadata->id', $assessment->id)->exists())->toBeTrue();
});

it('does not log a usage event when a model is created without an authenticated user', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    Tenancy::useSchool($school);
    AnnualPlan::factory()->create(['group_id' => $group->id]);
    Tenancy::forget();

    expect(UsageEvent::query()->where('event_type', 'annual_plan.created')->exists())->toBeFalse();
});
