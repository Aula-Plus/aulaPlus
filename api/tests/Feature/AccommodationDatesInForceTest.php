<?php

use App\Models\Accommodation;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * PO, ClickUp 86e3d1bwa (option A): every accommodation has «Vigente desde»,
 * filled with the day it becomes active and editable. No user-entered end
 * date: when it is switched off the system records that day on its own. Days
 * are school-local (America/Montevideo, UTC-3): 2026-05-04 01:30 UTC is still
 * 2026-05-03 in the school.
 */
function datesScenario(): array
{
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();

    return compact('school', 'student', 'psychopedagogue');
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-05-04 01:30:00', 'UTC'));
});

it('fills «Vigente desde» with today (school time) when the form does not send it', function () {
    ['student' => $student, 'psychopedagogue' => $psychopedagogue] = datesScenario();
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/students/{$student->id}/accommodations", [
        'category' => 'access',
        'type' => 'Tiempo extendido',
        'description' => 'x',
        'focus_area' => 'attention',
    ])->assertCreated()
        ->assertJsonPath('data.starts_on', '2026-05-03')
        ->assertJsonPath('data.deactivated_on', null);
});

it('keeps an explicit start date on create and lets it be edited', function () {
    ['student' => $student, 'psychopedagogue' => $psychopedagogue] = datesScenario();
    Sanctum::actingAs($psychopedagogue);

    $id = $this->postJson("/api/v1/students/{$student->id}/accommodations", [
        'category' => 'access',
        'type' => 'Tiempo extendido',
        'description' => 'x',
        'focus_area' => 'attention',
        'starts_on' => '2026-03-10',
    ])->assertCreated()->assertJsonPath('data.starts_on', '2026-03-10')->json('data.id');

    $this->patchJson("/api/v1/accommodations/{$id}", [
        'category' => 'access',
        'starts_on' => '2026-03-02',
    ])->assertOk()->assertJsonPath('data.starts_on', '2026-03-02');
});

it('records the day it is switched off, without touching the start date', function () {
    ['student' => $student, 'psychopedagogue' => $psychopedagogue] = datesScenario();
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'starts_on' => '2026-03-10']);
    Sanctum::actingAs($psychopedagogue);

    $this->patchJson("/api/v1/accommodations/{$accommodation->id}", [
        'category' => 'access',
        'active' => false,
        'starts_on' => '2026-03-10',
    ])->assertOk()
        ->assertJsonPath('data.deactivated_on', '2026-05-03')
        ->assertJsonPath('data.starts_on', '2026-03-10');

    // A later edit of an already-inactive one does not move the date.
    $this->travelTo(Carbon::parse('2026-05-20 12:00:00', 'UTC'));
    $this->patchJson("/api/v1/accommodations/{$accommodation->id}", [
        'category' => 'criteria',
        'active' => false,
    ])->assertOk()->assertJsonPath('data.deactivated_on', '2026-05-03');
});

it('never lets a client set the deactivation date', function () {
    ['student' => $student, 'psychopedagogue' => $psychopedagogue] = datesScenario();
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'starts_on' => '2026-03-10']);
    Sanctum::actingAs($psychopedagogue);

    $this->patchJson("/api/v1/accommodations/{$accommodation->id}", [
        'category' => 'access',
        'deactivated_on' => '2026-04-01',
    ])->assertOk()->assertJsonPath('data.deactivated_on', null);

    $this->patchJson("/api/v1/accommodations/{$accommodation->id}", [
        'category' => 'access',
        'active' => false,
        'deactivated_on' => '2026-04-01',
    ])->assertOk()->assertJsonPath('data.deactivated_on', '2026-05-03');

    $created = $this->postJson("/api/v1/students/{$student->id}/accommodations", [
        'category' => 'access',
        'type' => 'Tiempo extendido',
        'description' => 'x',
        'focus_area' => 'attention',
        'deactivated_on' => '2026-04-01',
    ])->assertCreated();

    expect(Accommodation::find($created->json('data.id'))->deactivated_on)->toBeNull();
});

it('restarts «Vigente desde» on re-activation unless the same edit sets it', function () {
    ['student' => $student, 'psychopedagogue' => $psychopedagogue] = datesScenario();
    $accommodation = Accommodation::factory()->create(['student_id' => $student->id, 'starts_on' => '2026-03-10']);
    $accommodation->update(['active' => false]);
    Sanctum::actingAs($psychopedagogue);

    $this->travelTo(Carbon::parse('2026-06-01 15:00:00', 'UTC'));
    $this->patchJson("/api/v1/accommodations/{$accommodation->id}", [
        'category' => 'access',
        'active' => true,
        // The form resends the unchanged date: still "not set explicitly".
        'starts_on' => '2026-03-10',
    ])->assertOk()
        ->assertJsonPath('data.starts_on', '2026-06-01')
        ->assertJsonPath('data.deactivated_on', null);

    $accommodation->refresh()->update(['active' => false]);
    $this->patchJson("/api/v1/accommodations/{$accommodation->id}", [
        'category' => 'access',
        'active' => true,
        'starts_on' => '2026-05-25',
    ])->assertOk()
        ->assertJsonPath('data.starts_on', '2026-05-25')
        ->assertJsonPath('data.deactivated_on', null);
});

// ClickUp 86e3qkwkk: a direction proposal becomes effective the day
// psychopedagogy confirms it (the external-approval flow was removed).
it('starts a confirmed proposal on the confirmation day, keeping a future start', function () {
    ['student' => $student, 'psychopedagogue' => $psychopedagogue] = datesScenario();
    $pending = Accommodation::factory()->proposal()->create([
        'student_id' => $student->id,
        'starts_on' => '2026-04-20',
    ]);
    $planned = Accommodation::factory()->proposal()->create([
        'student_id' => $student->id,
        'starts_on' => '2026-06-15',
    ]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/accommodations/{$pending->id}/proposal/confirm")
        ->assertOk()
        ->assertJsonPath('data.starts_on', '2026-05-03');
    $this->postJson("/api/v1/accommodations/{$planned->id}/proposal/confirm")
        ->assertOk()
        ->assertJsonPath('data.starts_on', '2026-06-15');
});

it('knows which accommodations were in force on a given day', function () {
    ['school' => $school, 'student' => $student] = datesScenario();
    Tenancy::useSchool($school);

    $make = fn (array $attributes) => Accommodation::factory()->create([
        'student_id' => $student->id,
        'starts_on' => '2026-03-10',
        ...$attributes,
    ]);

    $current = $make(['type' => 'current']);
    $ended = $make(['type' => 'ended', 'active' => false, 'deactivated_on' => '2026-04-15']);
    $neverOn = $make(['type' => 'never-on', 'active' => false]);
    $pending = $make(['type' => 'pending', 'requires_external_approval' => true, 'approved' => null]);
    $future = $make(['type' => 'future', 'starts_on' => '2026-06-01']);

    $inForce = fn (string $day) => Accommodation::query()->inForceOn($day)->orderBy('type')->pluck('type')->all();

    expect($inForce('2026-03-09'))->toBe([])
        ->and($inForce('2026-03-10'))->toBe(['current', 'ended'])
        ->and($inForce('2026-04-14'))->toBe(['current', 'ended'])
        // The deactivation day no longer counts.
        ->and($inForce('2026-04-15'))->toBe(['current'])
        ->and($inForce('2026-06-01'))->toBe(['current', 'future']);

    // The in-memory twin agrees.
    foreach (['2026-03-09', '2026-03-10', '2026-04-14', '2026-04-15', '2026-06-01'] as $day) {
        $inMemory = collect([$current, $ended, $neverOn, $pending, $future])
            ->filter(fn (Accommodation $accommodation) => $accommodation->fresh()->isInForceOn($day))
            ->pluck('type')->sort()->values()->all();

        expect($inMemory)->toBe($inForce($day));
    }
});

it('backfills the deactivation day of accommodations already switched off', function () {
    ['school' => $school, 'student' => $student] = datesScenario();
    Tenancy::useSchool($school);
    $migration = require database_path('migrations/2026_10_10_000002_add_deactivated_on_to_accommodations.php');

    $flipped = Accommodation::factory()->create(['student_id' => $student->id, 'starts_on' => '2026-03-10']);
    $noLog = Accommodation::factory()->create(['student_id' => $student->id, 'starts_on' => '2026-03-10']);
    $active = Accommodation::factory()->create(['student_id' => $student->id, 'starts_on' => '2026-03-10']);
    $rejected = Accommodation::factory()->create([
        'student_id' => $student->id,
        'starts_on' => '2026-03-10',
        'requires_external_approval' => true,
        'approved' => false,
    ]);

    $migration->down();

    DB::table('accommodations')->whereIn('id', [$flipped->id, $noLog->id, $rejected->id])->update(['active' => false]);
    DB::table('accommodations')->where('id', $noLog->id)->update(['updated_at' => '2026-04-20 02:00:00']);
    // Switched off late on April 2nd, Montevideo time (April 3rd in UTC).
    DB::table('audit_logs')->insert([
        'school_id' => $school->id,
        'auditable_type' => Accommodation::class,
        'auditable_id' => $flipped->id,
        'action' => 'updated',
        'origin' => 'system',
        'changes' => json_encode(['active' => ['before' => true, 'after' => false]]),
        'created_at' => '2026-04-03 01:00:00',
    ]);

    $migration->up();

    $deactivatedOn = fn (Accommodation $accommodation) => DB::table('accommodations')
        ->where('id', $accommodation->id)->value('deactivated_on');

    expect(substr((string) $deactivatedOn($flipped), 0, 10))->toBe('2026-04-02')
        ->and(substr((string) $deactivatedOn($noLog), 0, 10))->toBe('2026-04-19')
        ->and($deactivatedOn($active))->toBeNull()
        ->and($deactivatedOn($rejected))->toBeNull();
});

it('backfills an empty interval for one created inactive and never switched on, even if edited later', function () {
    ['school' => $school, 'student' => $student] = datesScenario();
    Tenancy::useSchool($school);
    $migration = require database_path('migrations/2026_10_10_000002_add_deactivated_on_to_accommodations.php');

    $neverOn = Accommodation::factory()->create([
        'student_id' => $student->id,
        'starts_on' => '2026-03-10',
        'active' => false,
    ]);
    // A later edit that leaves `active` alone: audited, but no true → false flip.
    $neverOn->update(['focus_area' => 'literacy']);

    $migration->down();
    DB::table('accommodations')->where('id', $neverOn->id)->update(['updated_at' => '2026-04-20 15:00:00']);
    $migration->up();

    expect(substr((string) DB::table('accommodations')->where('id', $neverOn->id)->value('deactivated_on'), 0, 10))
        ->toBe('2026-03-10')
        ->and(Accommodation::find($neverOn->id)->isInForceOn('2026-04-01'))->toBeFalse();
});
