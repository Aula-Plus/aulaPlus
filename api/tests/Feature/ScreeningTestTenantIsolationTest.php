<?php

use App\Models\Group;
use App\Models\School;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestResult;
use App\Models\ScreeningTestType;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

afterEach(fn () => Tenancy::forget());

/**
 * Multi-tenancy: no screening entity leaks or is affectable across schools
 * (docs/prompts/11-pruebas-de-sondeo.md §5).
 */
it('never leaks a screening entity across schools at the model layer', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    Tenancy::useSchool($schoolA);
    $typeA = ScreeningTestType::factory()->create(['school_id' => $schoolA->id]);
    ScreeningTestDesign::factory()->for($typeA, 'type')->approved()->create();
    $groupA = Group::factory()->create(['school_id' => $schoolA->id]);
    $appA = ScreeningTestApplication::factory()->create([
        'school_id' => $schoolA->id, 'group_id' => $groupA->id, 'screening_test_type_id' => $typeA->id,
    ]);
    ScreeningTestResult::factory()->create([
        'school_id' => $schoolA->id,
        'screening_test_application_id' => $appA->id,
        'student_id' => Student::factory()->create(['school_id' => $schoolA->id]),
    ]);

    Tenancy::useSchool($schoolB);

    expect(ScreeningTestType::count())->toBe(0)
        ->and(ScreeningTestDesign::count())->toBe(0)
        ->and(ScreeningTestApplication::count())->toBe(0)
        ->and(ScreeningTestResult::count())->toBe(0);

    Tenancy::useSchool($schoolA);

    expect(ScreeningTestType::count())->toBe(1)
        ->and(ScreeningTestDesign::count())->toBe(1)
        ->and(ScreeningTestApplication::count())->toBe(1)
        ->and(ScreeningTestResult::count())->toBe(1);
});

it('does not let a user of another school read or affect an application', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    Tenancy::useSchool($schoolA);
    $typeA = ScreeningTestType::factory()->create(['school_id' => $schoolA->id]);
    ScreeningTestDesign::factory()->for($typeA, 'type')->approved()->create();
    $groupA = Group::factory()->create(['school_id' => $schoolA->id]);
    $appA = ScreeningTestApplication::factory()->create([
        'school_id' => $schoolA->id, 'group_id' => $groupA->id, 'screening_test_type_id' => $typeA->id,
    ]);
    $resultA = ScreeningTestResult::factory()->create([
        'school_id' => $schoolA->id,
        'screening_test_application_id' => $appA->id,
        'student_id' => Student::factory()->create(['school_id' => $schoolA->id]),
        'code' => '01',
    ]);
    Tenancy::forget();

    // A psychopedagogue in school B — highest-privileged role for this module —
    // still cannot reach school A's rows: route-model binding is tenant-scoped,
    // so they 404 rather than 403.
    $intruder = User::factory()->forSchool($schoolB)->psychopedagogue()->create();
    Sanctum::actingAs($intruder);

    $this->getJson("/api/v1/screening-test-applications/{$appA->id}/roster")->assertNotFound();
    $this->getJson("/api/v1/screening-test-applications/{$appA->id}/results")->assertNotFound();
    $this->patchJson("/api/v1/screening-test-results/{$resultA->id}", ['score' => 50])->assertNotFound();
    $this->getJson("/api/v1/groups/{$groupA->id}/screening-test-applications")->assertNotFound();

    Tenancy::useSchool($schoolA);
    expect($resultA->fresh()->score)->toBeNull();
});

it('does not let a user of another school apply using a foreign type', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    Tenancy::useSchool($schoolA);
    $typeA = ScreeningTestType::factory()->create(['school_id' => $schoolA->id]);
    ScreeningTestDesign::factory()->for($typeA, 'type')->approved()->create();
    Tenancy::forget();

    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);
    $psychopedagogueB = User::factory()->forSchool($schoolB)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogueB);

    // The foreign type id fails validation (exists rule is scoped to the user's
    // school) → 422, and no application is created.
    $this->postJson("/api/v1/groups/{$groupB->id}/screening-test-applications", [
        'screening_test_type_id' => $typeA->id,
    ])->assertStatus(422);

    Tenancy::useSchool($schoolB);
    expect(ScreeningTestApplication::count())->toBe(0);
});
