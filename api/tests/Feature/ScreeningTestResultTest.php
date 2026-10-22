<?php

use App\Enums\ScreeningColor;
use App\Models\Group;
use App\Models\School;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestResult;
use App\Models\ScreeningTestType;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

afterEach(fn () => Tenancy::forget());

/**
 * Loading scores + color computation (docs/prompts/11-pruebas-de-sondeo.md §3).
 */

/**
 * A ready-to-load result: an approved design (cutoffs $low/$high) applied to a
 * one-student group, returning the first result and the acting psychopedagogue.
 *
 * @return array{result: ScreeningTestResult, psychopedagogue: User, type: ScreeningTestType, school: School}
 */
function screeningResultReadyToLoad(float $low = 40, float $high = 70): array
{
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Ana Díaz']);
    $group->students()->attach($student->id, ['school_year' => now()->year]);

    $type = ScreeningTestType::factory()->create(['school_id' => $school->id]);
    ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['cutoff_low' => $low, 'cutoff_high' => $high]);

    $application = ScreeningTestApplication::factory()->create([
        'school_id' => $school->id,
        'group_id' => $group->id,
        'screening_test_type_id' => $type->id,
    ]);
    $result = ScreeningTestResult::factory()->create([
        'school_id' => $school->id,
        'screening_test_application_id' => $application->id,
        'student_id' => $student->id,
        'code' => '01',
    ]);

    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();

    return compact('result', 'psychopedagogue', 'type', 'school');
}

it('computes the color band for a known score in each band and at each exact cutoff', function (float $score, string $expected) {
    ['result' => $result, 'psychopedagogue' => $psychopedagogue] = screeningResultReadyToLoad(40, 70);
    Sanctum::actingAs($psychopedagogue);

    $this->patchJson("/api/v1/screening-test-results/{$result->id}", ['score' => $score])
        ->assertOk()
        ->assertJsonPath('data.color', $expected)
        ->assertJsonPath('data.loaded_by_id', $psychopedagogue->id);

    expect($result->fresh()->color)->toBe(ScreeningColor::from($expected));
    expect($result->fresh()->loaded_at)->not->toBeNull();
})->with([
    'below low → red' => [30.0, 'red'],
    'exactly low → red' => [40.0, 'red'],
    'between → yellow' => [55.0, 'yellow'],
    'exactly high → green' => [70.0, 'green'],
    'above high → green' => [85.0, 'green'],
]);

it('forbids a teacher and a director from loading a score', function () {
    ['result' => $result, 'school' => $school] = screeningResultReadyToLoad();

    $teacher = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($teacher);
    $this->patchJson("/api/v1/screening-test-results/{$result->id}", ['score' => 50])->assertForbidden();

    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);
    $this->patchJson("/api/v1/screening-test-results/{$result->id}", ['score' => 50])->assertForbidden();
});

it('returns 422 when loading a score after the design was rejected', function () {
    ['result' => $result, 'psychopedagogue' => $psychopedagogue, 'type' => $type] = screeningResultReadyToLoad();

    // The only approved design is rejected after the application was created.
    Tenancy::useSchool($type->school_id);
    $type->designs()->update(['approved' => false]);

    Sanctum::actingAs($psychopedagogue);
    $this->patchJson("/api/v1/screening-test-results/{$result->id}", ['score' => 50])->assertStatus(422);

    expect($result->fresh()->score)->toBeNull();
});

it('does not recompute an already-loaded color when a new design is approved', function () {
    ['result' => $result, 'psychopedagogue' => $psychopedagogue, 'type' => $type, 'school' => $school] =
        screeningResultReadyToLoad(40, 70);
    Sanctum::actingAs($psychopedagogue);

    // Load 55 under design v1 (40/70) → yellow.
    $this->patchJson("/api/v1/screening-test-results/{$result->id}", ['score' => 55])
        ->assertOk()
        ->assertJsonPath('data.color', 'yellow');

    // A newer approved design (60/90) would classify 55 as red.
    Tenancy::useSchool($school->id);
    ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['cutoff_low' => 60, 'cutoff_high' => 90]);

    // The already-loaded color is persisted, not derived — it stays yellow.
    expect($result->fresh()->color)->toBe(ScreeningColor::Yellow);

    // Loading a brand-new score now uses the newer design: 55 → red.
    $freshResult = ScreeningTestResult::factory()->create([
        'school_id' => $school->id,
        'screening_test_application_id' => $result->screening_test_application_id,
        'student_id' => Student::factory()->create(['school_id' => $school->id]),
        'code' => '02',
    ]);
    $this->patchJson("/api/v1/screening-test-results/{$freshResult->id}", ['score' => 55])
        ->assertOk()
        ->assertJsonPath('data.color', 'red');
});

it('backfills the band of results loaded before free bands and does not flag them as changed', function () {
    ['result' => $result, 'psychopedagogue' => $psychopedagogue, 'type' => $type, 'school' => $school] =
        screeningResultReadyToLoad(40, 70);

    // A result loaded under the legacy red/yellow/green design: colour only,
    // no band name and no design version (what existed before free bands).
    DB::table('screening_test_results')->where('id', $result->id)->update([
        'score' => 55,
        'color' => 'yellow',
        'band' => null,
        'screening_test_design_id' => null,
    ]);

    (require database_path('migrations/2026_10_16_000004_backfill_band_of_legacy_screening_test_results.php'))->up();

    expect($result->fresh()->band)->toBe('Amarillo');

    Sanctum::actingAs($psychopedagogue);
    $resultsUrl = "/api/v1/screening-test-applications/{$result->screening_test_application_id}/results";

    // Same legacy design still in force: same reading, nothing changed.
    $this->getJson($resultsUrl)
        ->assertOk()
        ->assertJsonPath('data.0.band', 'Amarillo')
        ->assertJsonPath('data.0.current_band', 'Amarillo')
        ->assertJsonPath('data.0.band_changed', false);

    // A new design that reads 55 differently does flag it.
    Tenancy::useSchool($school->id);
    ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['bands' => [
        ['name' => 'Descendido', 'color' => 'red', 'max' => 60, 'meaning' => 'a'],
        ['name' => 'Adecuado', 'color' => 'green', 'max' => null, 'meaning' => 'b'],
    ]]);

    $this->getJson($resultsUrl)
        ->assertJsonPath('data.0.current_band', 'Descendido')
        ->assertJsonPath('data.0.band_changed', true);
});

it('does not flag a result with an unknown original band name when its colour still matches', function () {
    ['result' => $result, 'psychopedagogue' => $psychopedagogue] = screeningResultReadyToLoad(40, 70);
    DB::table('screening_test_results')->where('id', $result->id)->update([
        'score' => 80,
        'color' => 'green',
        'band' => null,
        'screening_test_design_id' => null,
    ]);
    Sanctum::actingAs($psychopedagogue);

    $this->getJson("/api/v1/screening-test-applications/{$result->screening_test_application_id}/results")
        ->assertJsonPath('data.0.current_color', 'green')
        ->assertJsonPath('data.0.band_changed', false);
});

it('keeps the original reading and shows the current one when the cutoffs change', function () {
    ['result' => $result, 'psychopedagogue' => $psychopedagogue, 'type' => $type, 'school' => $school] =
        screeningResultReadyToLoad(40, 70);
    Sanctum::actingAs($psychopedagogue);

    $loaded = $this->patchJson("/api/v1/screening-test-results/{$result->id}", ['score' => 55])
        ->assertOk()
        ->assertJsonPath('data.band', 'Amarillo')
        ->assertJsonPath('data.band_changed', false);
    $originalDesignId = $loaded->json('data.screening_test_design_id');
    expect($originalDesignId)->not->toBeNull();

    // New cutoffs: up to 60 is "Descendido".
    Tenancy::useSchool($school->id);
    ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['bands' => [
        ['name' => 'Descendido', 'color' => 'red', 'max' => 60, 'meaning' => 'a'],
        ['name' => 'Adecuado', 'color' => 'green', 'max' => null, 'meaning' => 'b'],
    ]]);

    $this->getJson("/api/v1/screening-test-applications/{$result->screening_test_application_id}/results")
        ->assertOk()
        // Original score, band and design version are kept…
        ->assertJsonPath('data.0.score', '55.00')
        ->assertJsonPath('data.0.band', 'Amarillo')
        ->assertJsonPath('data.0.screening_test_design_id', $originalDesignId)
        // …and the reading with the design in force is added.
        ->assertJsonPath('data.0.current_band', 'Descendido')
        ->assertJsonPath('data.0.current_color', 'red')
        ->assertJsonPath('data.0.band_changed', true);
});
