<?php

use App\Models\Accommodation;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\School;
use App\Models\SchoolGrade;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestResult;
use App\Models\ScreeningTestType;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

/**
 * Screen 05 «Comparativa por año de cursada» (ClickUp 1dfd3mc4bgt): the
 * groups of one grade side by side, aggregate only, for Psicopedagogía and
 * Dirección.
 */
function comparisonGradeId(string $key): int
{
    return SchoolGrade::query()->where('key', $key)->value('id');
}

function comparisonScenario(): array
{
    $school = School::factory()->create();
    Tenancy::useSchool($school->id);
    $year = now()->year;
    $grade = comparisonGradeId('primary_5');
    $a = Group::factory()->create(['school_id' => $school->id, 'name' => '5.°A', 'school_grade_id' => $grade, 'school_year' => $year]);
    $b = Group::factory()->create(['school_id' => $school->id, 'name' => '5.°B', 'school_grade_id' => $grade, 'school_year' => $year]);
    // Alone in its grade: not comparable.
    Group::factory()->create(['school_id' => $school->id, 'name' => '6.°A', 'school_grade_id' => comparisonGradeId('primary_6'), 'school_year' => $year]);
    $math = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $a->teachers()->attach($teacher->id, ['subject_id' => $math->id]);
    $b->teachers()->attach($teacher->id, ['subject_id' => $math->id]);

    $studentsA = Student::factory()->count(2)->create(['school_id' => $school->id]);
    $studentsB = Student::factory()->count(3)->create(['school_id' => $school->id]);
    $a->students()->attach($studentsA->pluck('id'), ['school_year' => $year]);
    $b->students()->attach($studentsB->pluck('id'), ['school_year' => $year]);

    $testA = Assessment::factory()->for($a)->create(['subject_id' => $math->id]);
    AssessmentResult::factory()->for($testA)->for($studentsA[0])->create(['score' => 8]);
    AssessmentResult::factory()->for($testA)->for($studentsA[1])->create(['score' => 7]);
    $testB = Assessment::factory()->for($b)->create(['subject_id' => $math->id]);
    AssessmentResult::factory()->for($testB)->for($studentsB[0])->create(['score' => 6]);

    Accommodation::factory()->create(['school_id' => $school->id, 'student_id' => $studentsB[0]->id, 'type' => 'Tiempo extendido 25%', 'active' => true]);
    Accommodation::factory()->create(['school_id' => $school->id, 'student_id' => $studentsB[1]->id, 'type' => 'Tiempo extendido 25%', 'active' => true]);

    $type = ScreeningTestType::factory()->create(['school_id' => $school->id, 'name' => 'Comprensión lectora']);
    $design = ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['bands' => [
        ['name' => 'Inicial', 'color' => 'red', 'max' => 30, 'meaning' => 'x'],
        ['name' => 'En proceso', 'color' => 'yellow', 'max' => 60, 'meaning' => 'x'],
        ['name' => 'Adecuado', 'color' => 'green', 'max' => 100, 'meaning' => 'x'],
    ]]);
    $old = ScreeningTestApplication::factory()->create([
        'school_id' => $school->id, 'screening_test_type_id' => $type->id, 'group_id' => $a->id, 'application_date' => now()->subMonths(6)->toDateString(),
    ]);
    $latest = ScreeningTestApplication::factory()->create([
        'school_id' => $school->id, 'screening_test_type_id' => $type->id, 'group_id' => $a->id, 'application_date' => now()->subMonth()->toDateString(),
    ]);
    ScreeningTestResult::factory()->create(['school_id' => $school->id, 'screening_test_application_id' => $old->id, 'student_id' => $studentsA[0]->id, 'score' => 10, 'band' => 'Inicial', 'screening_test_design_id' => $design->id]);
    ScreeningTestResult::factory()->create(['school_id' => $school->id, 'screening_test_application_id' => $latest->id, 'student_id' => $studentsA[0]->id, 'score' => 50, 'band' => 'En proceso', 'screening_test_design_id' => $design->id]);
    ScreeningTestResult::factory()->create(['school_id' => $school->id, 'screening_test_application_id' => $latest->id, 'student_id' => $studentsA[1]->id, 'score' => 90, 'band' => 'Adecuado', 'screening_test_design_id' => $design->id]);

    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    return compact('school', 'year', 'grade', 'a', 'b', 'teacher', 'psycho', 'director', 'studentsA');
}

it('lists only the years with two or more groups', function () {
    ['psycho' => $psycho, 'grade' => $grade, 'year' => $year, 'a' => $a, 'b' => $b] = comparisonScenario();
    Sanctum::actingAs($psycho);

    $this->getJson('/api/v1/group-comparison/options')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.school_grade_id', $grade)
        ->assertJsonPath('data.0.school_grade', '5° de Primaria')
        ->assertJsonPath('data.0.school_year', $year)
        ->assertJsonPath('data.0.groups.0.id', $a->id)
        ->assertJsonPath('data.0.groups.1.id', $b->id);
});

it('puts the groups side by side with aggregate values only', function () {
    ['director' => $director, 'grade' => $grade, 'year' => $year, 'studentsA' => $studentsA] = comparisonScenario();
    Sanctum::actingAs($director);

    $response = $this->getJson("/api/v1/group-comparison?school_grade_id={$grade}&school_year={$year}")->assertOk()
        ->assertJsonPath('data.school_grade', '5° de Primaria')
        ->assertJsonCount(2, 'data.groups')
        // Name order, never ranked: 5.°A first although its average is higher.
        ->assertJsonPath('data.groups.0.name', '5.°A')
        ->assertJsonPath('data.groups.0.student_count', 2)
        ->assertJsonPath('data.groups.0.subject_averages.0.subject_name', 'Matemática')
        ->assertJsonPath('data.groups.0.subject_averages.0.average', 7.5)
        ->assertJsonPath('data.groups.0.accommodations_by_type', [])
        // Latest application only, every band of the design in order.
        ->assertJsonPath('data.groups.0.screenings.0.name', 'Comprensión lectora')
        ->assertJsonPath('data.groups.0.screenings.0.bands', [
            ['name' => 'Inicial', 'count' => 0],
            ['name' => 'En proceso', 'count' => 1],
            ['name' => 'Adecuado', 'count' => 1],
        ])
        ->assertJsonPath('data.groups.1.name', '5.°B')
        ->assertJsonPath('data.groups.1.student_count', 3)
        ->assertJsonPath('data.groups.1.subject_averages.0.average', 6)
        ->assertJsonPath('data.groups.1.accommodations_by_type.0.type', 'Tiempo extendido 25%')
        ->assertJsonPath('data.groups.1.accommodations_by_type.0.student_count', 2)
        ->assertJsonPath('data.groups.1.screenings', []);

    // No student appears.
    expect($response->getContent())
        ->not->toContain($studentsA[0]->full_name)
        ->not->toContain('"student_id"');
});

it('is closed to teachers', function () {
    ['teacher' => $teacher, 'grade' => $grade, 'year' => $year] = comparisonScenario();
    Sanctum::actingAs($teacher);

    $this->getJson('/api/v1/group-comparison/options')->assertForbidden();
    $this->getJson("/api/v1/group-comparison?school_grade_id={$grade}&school_year={$year}")->assertForbidden();
});

it('never shows another school\'s groups', function () {
    ['grade' => $grade, 'year' => $year] = comparisonScenario();
    $other = School::factory()->create();
    $otherPsycho = User::factory()->forSchool($other)->psychopedagogue()->create();
    Tenancy::forget();
    Sanctum::actingAs($otherPsycho);

    $this->getJson('/api/v1/group-comparison/options')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/group-comparison?school_grade_id={$grade}&school_year={$year}")->assertOk()
        ->assertJsonCount(0, 'data.groups');
});

it('validates the year asked for and requires authentication', function () {
    ['psycho' => $psycho] = comparisonScenario();
    Sanctum::actingAs($psycho);

    $this->getJson('/api/v1/group-comparison?school_grade_id=99999&school_year=2026')->assertUnprocessable();
    $this->getJson('/api/v1/group-comparison')->assertUnprocessable();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/group-comparison/options')->assertUnauthorized();
});
