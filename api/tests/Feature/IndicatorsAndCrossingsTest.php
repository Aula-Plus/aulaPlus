<?php

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Crossing;
use App\Models\ExternalMeasure;
use App\Models\Group;
use App\Models\Indicator;
use App\Models\OfficialScale;
use App\Models\School;
use App\Models\SchoolGrade;
use App\Models\SchoolScaleEquivalence;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestResult;
use App\Models\ScreeningTestType;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\OfficialScaleSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Screen 30 «Indicadores y cruces» (ClickUp 1dfd3mc4bgp, first delivery):
 * Datos, Indicadores, Cruces and Gráfico de cruces, for Psicopedagogía and
 * Dirección only.
 */
function indicatorScenario(): array
{
    test()->seed(OfficialScaleSeeder::class);
    $school = School::factory()->create();
    Tenancy::useSchool($school->id);
    $year = now()->year;
    $grade = SchoolGrade::query()->where('key', 'primary_5')->value('id');
    $a = Group::factory()->create(['school_id' => $school->id, 'name' => '5.°A', 'school_grade_id' => $grade, 'school_year' => $year]);
    $b = Group::factory()->create(['school_id' => $school->id, 'name' => '5.°B', 'school_grade_id' => $grade, 'school_year' => $year]);
    $studentsA = Student::factory()->count(4)->create(['school_id' => $school->id]);
    $studentsB = Student::factory()->count(2)->create(['school_id' => $school->id]);
    $a->students()->attach($studentsA->pluck('id'), ['school_year' => $year]);
    $b->students()->attach($studentsB->pluck('id'), ['school_year' => $year]);

    // Comprensión lectora, taken every year: this year's scores.
    $reading = ScreeningTestType::factory()->create(['school_id' => $school->id, 'name' => 'Comprensión lectora', 'frequency' => 'yearly', 'taken_every_year' => true]);
    ScreeningTestDesign::factory()->for($reading, 'type')->approved()->create(['school_id' => $school->id, 'bands' => [
        ['name' => 'Inicial', 'color' => 'red', 'max' => 39, 'meaning' => 'x'],
        ['name' => 'En proceso', 'color' => 'yellow', 'max' => 59, 'meaning' => 'x'],
        ['name' => 'Adecuado', 'color' => 'green', 'max' => null, 'meaning' => 'x'],
    ]]);
    $scores = [[$a, $studentsA[0], 20], [$a, $studentsA[1], 30], [$a, $studentsA[2], 35], [$a, $studentsA[3], 80], [$b, $studentsB[0], 25], [$b, $studentsB[1], 90]];
    $applications = [];
    foreach ($scores as [$group, $student, $score]) {
        $applications[$group->id] ??= ScreeningTestApplication::factory()->create([
            'school_id' => $school->id, 'screening_test_type_id' => $reading->id, 'group_id' => $group->id, 'application_date' => now()->startOfYear()->addMonths(3)->toDateString(),
        ]);
        ScreeningTestResult::factory()->create(['school_id' => $school->id, 'screening_test_application_id' => $applications[$group->id]->id, 'student_id' => $student->id, 'score' => $score]);
    }

    // Idioma Español in Primaria, recorded with levels of progress.
    $scale = OfficialScale::query()->where('key', 'anep_ebi_primary')->with('levels')->firstOrFail();
    $language = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Idioma Español']);
    foreach ([$a, $b] as $group) {
        $test = Assessment::factory()->for($group)->create(['school_id' => $school->id, 'subject_id' => $language->id, 'administered_at' => now()->subDays(10)->toDateString()]);
        foreach ($group->students as $index => $student) {
            AssessmentResult::factory()->for($test)->for($student)->create([
                'school_id' => $school->id, 'score' => null, 'official_scale_level_id' => $scale->levels[$index === 0 ? 0 : 2]->id,
            ]);
        }
    }

    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create(['name' => 'Adriana Ruiz']);
    $director = User::factory()->forSchool($school)->director()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($a, $teacher, $language);

    return compact('school', 'year', 'a', 'b', 'studentsA', 'studentsB', 'reading', 'language', 'scale', 'psycho', 'director', 'teacher');
}

function readingIndicatorPayload(int $screeningId, array $overrides = []): array
{
    return [
        'name' => 'Herramienta de lectura (comprensión)',
        'calculation' => 'raw',
        'inputs' => [['type' => 'screening', 'id' => $screeningId]],
        'bands' => [
            ['name' => 'Bajo', 'color' => 'red', 'max' => 39, 'meaning' => 'El equipo lo mira de cerca este período.'],
            ['name' => 'Medio', 'color' => 'yellow', 'max' => 59, 'meaning' => 'Sin acción ahora; se vuelve a mirar.'],
            ['name' => 'Esperado', 'color' => 'green', 'max' => null, 'meaning' => 'No requiere acción.'],
        ],
        'display_places' => ['cross_chart', 'group_band_bars'],
        ...$overrides,
    ];
}

it('lists the available data with scale and coverage, without rubric criteria or names', function () {
    ['psycho' => $psycho, 'reading' => $reading, 'language' => $language, 'studentsA' => $studentsA] = indicatorScenario();
    Sanctum::actingAs($psycho);

    $response = $this->getJson('/api/v1/indicator-data')->assertOk()
        ->assertJsonPath('data.roster_count', 6)
        ->assertJsonPath('data.groups.0.name', '5.°A')
        ->assertJsonPath('data.sources.0.type', 'screening')
        ->assertJsonPath('data.sources.0.id', $reading->id)
        ->assertJsonPath('data.sources.0.coverage', 6)
        ->assertJsonPath('data.sources.0.scale', ['min' => 0, 'max' => 100])
        ->assertJsonPath('data.sources.0.taken_every_year', true)
        ->assertJsonPath('data.sources.1.type', 'subject')
        ->assertJsonPath('data.sources.1.id', $language->id)
        // Primaria without the school's equivalence: a level, no number.
        ->assertJsonPath('data.sources.1.coverage', 6)
        ->assertJsonPath('data.sources.1.number_coverage', 0)
        ->assertJsonPath('data.sources.1.level_coverage', 6);

    expect(collect($response->json('data.sources'))->pluck('type')->unique()->values()->all())->toBe(['screening', 'subject'])
        ->and($response->getContent())->not->toContain($studentsA[0]->full_name);
});

it('uses this year\'s take of an annual screening and the latest one, with its year, otherwise', function () {
    ['psycho' => $psycho, 'school' => $school, 'a' => $a, 'studentsA' => $studentsA] = indicatorScenario();
    $cognitive = ScreeningTestType::factory()->create(['school_id' => $school->id, 'name' => 'Prueba cognitiva general', 'frequency' => 'every_few_years', 'frequency_years' => 3, 'taken_every_year' => false]);
    $annual = ScreeningTestType::factory()->create(['school_id' => $school->id, 'name' => 'Velocidad lectora', 'taken_every_year' => true]);
    foreach ([$cognitive, $annual] as $type) {
        $old = ScreeningTestApplication::factory()->create([
            'school_id' => $school->id, 'screening_test_type_id' => $type->id, 'group_id' => $a->id, 'application_date' => now()->subYear()->toDateString(),
        ]);
        ScreeningTestResult::factory()->create(['school_id' => $school->id, 'screening_test_application_id' => $old->id, 'student_id' => $studentsA[0]->id, 'score' => 70]);
    }
    Sanctum::actingAs($psycho);

    $sources = collect($this->getJson('/api/v1/indicator-data')->assertOk()->json('data.sources'))->keyBy('name');

    expect($sources['Prueba cognitiva general']['coverage'])->toBe(1)
        ->and($sources['Prueba cognitiva general']['latest_year'])->toBe(now()->year - 1)
        ->and($sources['Velocidad lectora']['coverage'])->toBe(0);
});

it('builds an indicator with three bands and previews it in aggregate', function () {
    ['psycho' => $psycho, 'reading' => $reading, 'studentsA' => $studentsA] = indicatorScenario();
    Sanctum::actingAs($psycho);

    $preview = $this->postJson('/api/v1/indicators/preview', readingIndicatorPayload($reading->id))->assertOk()
        ->assertJsonPath('data.students_with_data', 6)
        ->assertJsonPath('data.bands', [
            ['name' => 'Bajo', 'color' => 'red', 'count' => 4],
            ['name' => 'Medio', 'color' => 'yellow', 'count' => 0],
            ['name' => 'Esperado', 'color' => 'green', 'count' => 2],
        ]);
    expect($preview->getContent())->not->toContain($studentsA[0]->full_name);

    $this->postJson('/api/v1/indicators', readingIndicatorPayload($reading->id))->assertCreated()
        ->assertJsonPath('data.name', 'Herramienta de lectura (comprensión)')
        ->assertJsonPath('data.inputs.0.name', 'Comprensión lectora')
        ->assertJsonPath('data.teacher_visible', false)
        ->assertJsonPath('data.bands.0.meaning', 'El equipo lo mira de cerca este período.')
        ->assertJsonPath('data.summary.bands.2.count', 2);

    $this->getJson('/api/v1/indicators')->assertOk()->assertJsonCount(1, 'data');
});

it('requires the meaning of every band and the right number of data', function () {
    ['director' => $director, 'reading' => $reading] = indicatorScenario();
    Sanctum::actingAs($director);

    $payload = readingIndicatorPayload($reading->id);
    $payload['bands'][1]['meaning'] = '';
    $this->postJson('/api/v1/indicators', $payload)->assertUnprocessable()->assertJsonValidationErrors('bands.1.meaning');

    $this->postJson('/api/v1/indicators', readingIndicatorPayload($reading->id, ['calculation' => 'difference']))
        ->assertUnprocessable()->assertJsonValidationErrors('inputs');

    $this->postJson('/api/v1/indicators', readingIndicatorPayload(999999))
        ->assertUnprocessable()->assertJsonValidationErrors('inputs.0.id');
});

it('computes a Z score within the same grade and year by default and says which population', function () {
    ['psycho' => $psycho, 'reading' => $reading] = indicatorScenario();
    Sanctum::actingAs($psycho);

    $payload = readingIndicatorPayload($reading->id, ['calculation' => 'z_score', 'bands' => [
        ['name' => 'Por debajo', 'color' => 'yellow', 'max' => -1, 'meaning' => 'x'],
        ['name' => 'En lo esperado', 'color' => 'green', 'max' => 1, 'meaning' => 'x'],
        ['name' => 'Por encima', 'color' => 'blue', 'max' => null, 'meaning' => 'x'],
    ]]);

    $this->postJson('/api/v1/indicators', $payload)->assertCreated()
        ->assertJsonPath('data.z_population', 'grade_year')
        ->assertJsonPath('data.summary.students_with_data', 6)
        ->assertJsonPath('data.summary.populations', [['kind' => 'grade_year', 'label' => '5° de Primaria', 'size' => 6]]);

    $this->postJson('/api/v1/indicators/preview', [...$payload, 'z_population' => 'group'])->assertOk()
        ->assertJsonPath('data.populations.0', ['kind' => 'group', 'label' => '5.°A', 'size' => 4])
        ->assertJsonPath('data.populations.1', ['kind' => 'group', 'label' => '5.°B', 'size' => 2]);
});

it('refuses an indicator that uses itself through another one', function () {
    ['psycho' => $psycho, 'reading' => $reading] = indicatorScenario();
    Sanctum::actingAs($psycho);

    $first = $this->postJson('/api/v1/indicators', readingIndicatorPayload($reading->id))->assertCreated()->json('data.id');
    $second = $this->postJson('/api/v1/indicators', readingIndicatorPayload($reading->id, [
        'name' => 'Sobre el primero', 'inputs' => [['type' => 'indicator', 'id' => $first]],
    ]))->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/indicators/{$first}", readingIndicatorPayload($reading->id, [
        'inputs' => [['type' => 'indicator', 'id' => $second]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('inputs.0.id');

    // In use by another indicator: cannot be deleted.
    $this->deleteJson("/api/v1/indicators/{$first}")->assertUnprocessable();
    $this->deleteJson("/api/v1/indicators/{$second}")->assertNoContent();
    $this->deleteJson("/api/v1/indicators/{$first}")->assertNoContent();
});

it('crosses the reading indicator with the level of progress of Idioma Español, counted per group without names', function () {
    ['psycho' => $psycho, 'reading' => $reading, 'language' => $language, 'scale' => $scale, 'studentsA' => $studentsA] = indicatorScenario();
    Sanctum::actingAs($psycho);
    $indicator = $this->postJson('/api/v1/indicators', readingIndicatorPayload($reading->id))->assertCreated()->json('data.id');

    $payload = [
        'name' => 'Lectura baja y nivel de avance alto',
        'reading' => 'No se asigna una conclusión cerrada: el equipo revisa la herramienta de lectura.',
        'conditions' => [
            ['source' => ['type' => 'indicator', 'id' => $indicator], 'operator' => 'band', 'value' => 'Bajo'],
            ['source' => ['type' => 'subject', 'id' => $language->id], 'operator' => 'level', 'value' => $scale->levels[2]->id],
        ],
    ];

    // Bajo: 3 in 5.°A (20, 30, 35) and 1 in 5.°B (25); level 3 leaves out the
    // first student of each group.
    $preview = $this->postJson('/api/v1/crossings/preview', $payload)->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.groups.0.group_name', '5.°A')
        ->assertJsonPath('data.groups.0.count', 2)
        ->assertJsonCount(1, 'data.groups');
    expect($preview->getContent())->not->toContain($studentsA[1]->full_name);

    $single = [...$payload, 'conditions' => [$payload['conditions'][0]]];
    $this->postJson('/api/v1/crossings/preview', $single)->assertOk()
        ->assertJsonPath('data.groups', [
            ['group_id' => $studentsA[0]->groups()->first()->id, 'group_name' => '5.°A', 'count' => 3],
            ['group_id' => Group::query()->where('name', '5.°B')->value('id'), 'group_name' => '5.°B', 'count' => 1],
        ]);

    $this->postJson('/api/v1/crossings', $payload)->assertCreated()
        ->assertJsonPath('data.conditions.0.source.name', 'Herramienta de lectura (comprensión)')
        ->assertJsonPath('data.conditions.1.source.name', 'Idioma Español')
        ->assertJsonPath('data.preview.total', 2);

    $this->getJson('/api/v1/crossings')->assertOk()->assertJsonCount(1, 'data');
    // The indicator is now used by the crossing.
    $this->deleteJson("/api/v1/indicators/{$indicator}")->assertUnprocessable();
});

it('averages Primaria levels only through the school\'s equivalence', function () {
    ['psycho' => $psycho, 'school' => $school, 'language' => $language, 'scale' => $scale] = indicatorScenario();
    foreach ($scale->levels as $level) {
        SchoolScaleEquivalence::query()->create(['school_id' => $school->id, 'official_scale_level_id' => $level->id, 'numeric_grade' => min(10, $level->position + 2)]);
    }
    Sanctum::actingAs($psycho);

    $subject = collect($this->getJson('/api/v1/indicator-data')->json('data.sources'))->firstWhere('type', 'subject');
    expect($subject['number_coverage'])->toBe(6);

    $this->postJson('/api/v1/crossings/preview', ['conditions' => [
        ['source' => ['type' => 'subject', 'id' => $language->id], 'operator' => 'below', 'value' => $scale->levels[2]->position + 2],
    ]])->assertOk()->assertJsonPath('data.total', 2);
});

it('crosses on the grade of one assessment and validates each condition', function () {
    ['psycho' => $psycho, 'school' => $school, 'b' => $b, 'studentsB' => $studentsB, 'reading' => $reading] = indicatorScenario();
    $math = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']);
    $test = Assessment::factory()->for($b)->create(['school_id' => $school->id, 'subject_id' => $math->id]);
    AssessmentResult::factory()->for($test)->for($studentsB[0])->create(['school_id' => $school->id, 'score' => 4]);
    AssessmentResult::factory()->for($test)->for($studentsB[1])->create(['school_id' => $school->id, 'score' => 9]);
    Sanctum::actingAs($psycho);

    $this->postJson('/api/v1/crossings/preview', ['conditions' => [
        ['source' => ['type' => 'assessment', 'id' => $test->id], 'operator' => 'below', 'value' => 6],
    ]])->assertOk()->assertJsonPath('data.groups', [['group_id' => $b->id, 'group_name' => '5.°B', 'count' => 1]]);

    $this->postJson('/api/v1/crossings', ['name' => 'x', 'reading' => 'y', 'conditions' => [
        ['source' => ['type' => 'screening', 'id' => $reading->id], 'operator' => 'band', 'value' => 'No existe'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('conditions.0.value');

    $this->postJson('/api/v1/crossings', ['name' => 'x', 'conditions' => [
        ['source' => ['type' => 'screening', 'id' => $reading->id], 'operator' => 'band', 'value' => 'Inicial'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('reading');
});

it('loads an external datum by hand or CSV, skipping students of another school', function () {
    ['director' => $director, 'a' => $a, 'studentsA' => $studentsA] = indicatorScenario();
    $foreign = Student::factory()->create(['school_id' => School::factory()->create()->id]);
    Sanctum::actingAs($director);

    $id = $this->postJson('/api/v1/external-measures', ['name' => 'Prueba de vocabulario', 'scale_min' => 0, 'scale_max' => 50])
        ->assertCreated()->assertJsonPath('data.taken_every_year', true)->json('data.id');

    $this->putJson("/api/v1/external-measures/{$id}/values", ['values' => [
        ['student_id' => $studentsA[0]->id, 'value' => 40],
        ['student_id' => $studentsA[1]->id, 'value' => 80],
        ['student_id' => $foreign->id, 'value' => 10],
    ]])->assertOk()->assertJsonPath('data.saved', 1)->assertJsonPath('data.skipped', 2);

    $this->getJson("/api/v1/external-measures/{$id}/values?group_id={$a->id}")->assertOk()
        ->assertJsonCount(4, 'data.rows')
        ->assertJsonFragment(['student_id' => $studentsA[0]->id, 'value' => 40]);

    $source = collect($this->getJson('/api/v1/indicator-data')->json('data.sources'))->firstWhere('type', 'external');
    expect($source['coverage'])->toBe(1)->and($source['scale'])->toBe(['min' => 0, 'max' => 50]);

    $this->putJson("/api/v1/external-measures/{$id}/values", ['values' => [['student_id' => $studentsA[0]->id, 'value' => null]]])
        ->assertOk()->assertJsonPath('data.cleared', 1);
});

it('draws the chart with one point per student, names only on the point, levels on their own axis', function () {
    ['psycho' => $psycho, 'reading' => $reading, 'language' => $language, 'a' => $a, 'scale' => $scale] = indicatorScenario();
    Sanctum::actingAs($psycho);
    $indicator = $this->postJson('/api/v1/indicators', readingIndicatorPayload($reading->id))->json('data.id');

    $this->getJson("/api/v1/indicator-chart?x_type=indicator&x_id={$indicator}&y_type=subject&y_id={$language->id}")->assertOk()
        ->assertJsonCount(6, 'data.points')
        ->assertJsonPath('data.x.kind', 'number')
        ->assertJsonPath('data.x.cuts', [39, 59])
        ->assertJsonPath('data.y.kind', 'level')
        ->assertJsonPath('data.y.levels.0.id', $scale->levels[0]->id);

    $this->getJson("/api/v1/indicator-chart?x_type=indicator&x_id={$indicator}&y_type=subject&y_id={$language->id}&group_id={$a->id}")->assertOk()
        ->assertJsonCount(4, 'data.points')
        ->assertJsonPath('data.points.0.group_name', '5.°A');
});

it('is closed to teachers on every tab', function () {
    ['teacher' => $teacher, 'reading' => $reading, 'school' => $school] = indicatorScenario();
    $indicator = Indicator::factory()->create(['school_id' => $school->id, 'inputs' => [['type' => 'screening', 'id' => $reading->id]]]);
    $crossing = Crossing::factory()->create(['school_id' => $school->id]);
    $measure = ExternalMeasure::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson('/api/v1/indicator-data')->assertForbidden();
    $this->getJson('/api/v1/indicators')->assertForbidden();
    $this->postJson('/api/v1/indicators', readingIndicatorPayload($reading->id))->assertForbidden();
    $this->postJson('/api/v1/indicators/preview', readingIndicatorPayload($reading->id))->assertForbidden();
    $this->patchJson("/api/v1/indicators/{$indicator->id}", readingIndicatorPayload($reading->id))->assertForbidden();
    $this->deleteJson("/api/v1/indicators/{$indicator->id}")->assertForbidden();
    $this->getJson('/api/v1/crossings')->assertForbidden();
    $this->postJson('/api/v1/crossings/preview', ['conditions' => []])->assertForbidden();
    $this->deleteJson("/api/v1/crossings/{$crossing->id}")->assertForbidden();
    $this->postJson('/api/v1/external-measures', ['name' => 'x', 'scale_min' => 0, 'scale_max' => 10])->assertForbidden();
    $this->getJson("/api/v1/external-measures/{$measure->id}/values")->assertForbidden();
    $this->putJson("/api/v1/external-measures/{$measure->id}/values", ['values' => []])->assertForbidden();
    $this->getJson("/api/v1/indicator-chart?x_type=indicator&x_id={$indicator->id}&y_type=indicator&y_id={$indicator->id}")->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/indicator-data')->assertUnauthorized();
    $this->getJson('/api/v1/indicators')->assertUnauthorized();
    $this->getJson('/api/v1/crossings')->assertUnauthorized();
});

it('never shows or touches another school\'s data', function () {
    ['reading' => $reading, 'school' => $school] = indicatorScenario();
    $indicator = Indicator::factory()->create(['school_id' => $school->id, 'inputs' => [['type' => 'screening', 'id' => $reading->id]]]);
    $crossing = Crossing::factory()->create(['school_id' => $school->id]);
    $measure = ExternalMeasure::factory()->create(['school_id' => $school->id]);
    $other = School::factory()->create();
    $otherPsycho = User::factory()->forSchool($other)->psychopedagogue()->create();
    Tenancy::forget();
    Sanctum::actingAs($otherPsycho);

    $this->getJson('/api/v1/indicator-data')->assertOk()
        ->assertJsonPath('data.roster_count', 0)
        ->assertJsonCount(0, 'data.sources');
    $this->getJson('/api/v1/indicators')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/crossings')->assertOk()->assertJsonCount(0, 'data');
    $this->patchJson("/api/v1/indicators/{$indicator->id}", readingIndicatorPayload($reading->id))->assertNotFound();
    $this->deleteJson("/api/v1/crossings/{$crossing->id}")->assertNotFound();
    $this->getJson("/api/v1/external-measures/{$measure->id}/values")->assertNotFound();
    // Another school's screening cannot be used as a datum.
    $this->postJson('/api/v1/indicators/preview', readingIndicatorPayload($reading->id))
        ->assertUnprocessable()->assertJsonValidationErrors('inputs.0.id');
});

it('records «se toma todos los años» on a screening, defaulting from its frequency', function () {
    $school = School::factory()->create();
    $psycho = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psycho);

    $this->postJson('/api/v1/screening-test-types', ['name' => 'Atención', 'frequency' => 'twice_yearly'])->assertCreated()
        ->assertJsonPath('data.taken_every_year', true);
    $this->postJson('/api/v1/screening-test-types', ['name' => 'Cognitiva', 'frequency' => 'every_few_years', 'frequency_years' => 3])->assertCreated()
        ->assertJsonPath('data.taken_every_year', false);
    $id = $this->postJson('/api/v1/screening-test-types', ['name' => 'Lectura', 'frequency' => 'on_demand', 'taken_every_year' => true])->assertCreated()
        ->assertJsonPath('data.taken_every_year', true)->json('data.id');

    $this->patchJson("/api/v1/screening-test-types/{$id}", ['taken_every_year' => false])->assertOk()
        ->assertJsonPath('data.taken_every_year', false);
});

it('does not let an indicator drop or rename a band a crossing uses, and refuses repeated band names', function () {
    ['psycho' => $psycho, 'reading' => $reading] = indicatorScenario();
    Sanctum::actingAs($psycho);
    $indicator = $this->postJson('/api/v1/indicators', readingIndicatorPayload($reading->id))->assertCreated()->json('data.id');
    $this->postJson('/api/v1/crossings', ['name' => 'Lectura baja', 'reading' => 'El equipo revisa la herramienta.', 'conditions' => [
        ['source' => ['type' => 'indicator', 'id' => $indicator], 'operator' => 'band', 'value' => 'Bajo'],
    ]])->assertCreated();

    $renamed = readingIndicatorPayload($reading->id);
    $renamed['bands'][0]['name'] = 'Bajo rendimiento';
    $this->patchJson("/api/v1/indicators/{$indicator}", $renamed)
        ->assertUnprocessable()->assertJsonValidationErrors('bands');
    expect(Indicator::query()->find($indicator)->bands[0]['name'])->toBe('Bajo');

    // A band no crossing uses can still change.
    $other = readingIndicatorPayload($reading->id);
    $other['bands'][1]['name'] = 'Intermedio';
    $this->patchJson("/api/v1/indicators/{$indicator}", $other)->assertOk();

    $repeated = readingIndicatorPayload($reading->id);
    $repeated['bands'][1]['name'] = 'Esperado';
    $this->postJson('/api/v1/indicators', $repeated)
        ->assertUnprocessable()->assertJsonValidationErrors('bands.1.name');
});

it('lists the assessments with their levels in a fixed number of queries, and averages mixed results', function () {
    ['psycho' => $psycho, 'school' => $school, 'a' => $a, 'studentsA' => $studentsA, 'scale' => $scale] = indicatorScenario();
    $math = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']);
    SchoolScaleEquivalence::query()->create(['school_id' => $school->id, 'official_scale_level_id' => $scale->levels[1]->id, 'numeric_grade' => 4]);

    $countLevelQueries = function () use ($psycho): array {
        Sanctum::actingAs($psycho);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/indicator-data')->assertOk();
        $count = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'official_scale_levels') || str_contains($query['query'], 'assessment_results'))
            ->count();
        DB::disableQueryLog();

        return [$count, $response];
    };

    $addAssessment = function (string $date, array $results) use ($a, $school, $math): Assessment {
        $test = Assessment::factory()->for($a)->create(['school_id' => $school->id, 'subject_id' => $math->id, 'administered_at' => $date]);
        foreach ($results as [$student, $score, $levelId]) {
            AssessmentResult::factory()->for($test)->for($student)->create(['school_id' => $school->id, 'score' => $score, 'official_scale_level_id' => $levelId]);
        }

        return $test;
    };

    $levelTest = $addAssessment(now()->subDays(3)->toDateString(), [[$studentsA[0], null, $scale->levels[1]->id]]);
    $addAssessment(now()->subDays(5)->toDateString(), [[$studentsA[0], 6, null], [$studentsA[1], 7, null]]);
    [$before] = $countLevelQueries();

    foreach (range(1, 5) as $day) {
        $addAssessment(now()->subDays(20 + $day)->toDateString(), [[$studentsA[0], 8, null], [$studentsA[2], null, $scale->levels[2]->id]]);
    }
    [$after, $response] = $countLevelQueries();

    expect($after)->toBe($before);
    $assessments = collect($response->json('data.assessments'))->keyBy('id');
    expect(collect($assessments[$levelTest->id]['levels'])->pluck('id')->all())->toBe($scale->levels->pluck('id')->all());

    // Student 0 in Matemática: 6 and five 8s, plus a level worth 4 → (6 + 40 + 4) / 7.
    $this->postJson('/api/v1/crossings/preview', ['conditions' => [
        ['source' => ['type' => 'subject', 'id' => $math->id], 'operator' => 'above', 'value' => 7.13],
    ]])->assertOk()->assertJsonPath('data.total', 1);
    $this->postJson('/api/v1/crossings/preview', ['conditions' => [
        ['source' => ['type' => 'subject', 'id' => $math->id], 'operator' => 'above', 'value' => 7.15],
    ]])->assertOk()->assertJsonPath('data.total', 0);
    // Student 2 has only levels without equivalence: their latest level, no number.
    $this->postJson('/api/v1/crossings/preview', ['conditions' => [
        ['source' => ['type' => 'subject', 'id' => $math->id], 'operator' => 'level', 'value' => $scale->levels[2]->id],
    ]])->assertOk()->assertJsonPath('data.total', 1);
});

/**
 * Matemática in 5.°A with grades of three categories, oldest first:
 * student 0 → written 4 (day −30), oral 10 (day −20), written 6 (day −10);
 * student 1 → written 8 (day −30), oral 2 (day −20).
 */
function mathByCategory(array $scenario): Subject
{
    ['school' => $school, 'a' => $a, 'studentsA' => $studentsA] = $scenario;
    $math = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']);
    foreach ([
        [30, 'written', [[$studentsA[0], 4], [$studentsA[1], 8]]],
        [20, 'oral', [[$studentsA[0], 10], [$studentsA[1], 2]]],
        [10, 'written', [[$studentsA[0], 6]]],
    ] as [$daysAgo, $type, $results]) {
        $test = Assessment::factory()->for($a)->create([
            'school_id' => $school->id, 'subject_id' => $math->id, 'type' => $type, 'administered_at' => now()->subDays($daysAgo)->toDateString(),
        ]);
        foreach ($results as [$student, $score]) {
            AssessmentResult::factory()->for($test)->for($student)->create(['school_id' => $school->id, 'score' => $score, 'official_scale_level_id' => null]);
        }
    }

    return $math;
}

it('lets the creator say exactly which grades of a subject a crossing reads', function () {
    $scenario = indicatorScenario();
    $math = mathByCategory($scenario);
    Sanctum::actingAs($scenario['psycho']);

    $count = fn (?array $grades, string $operator, float $value): int => $this->postJson('/api/v1/crossings/preview', ['conditions' => [
        ['source' => ['type' => 'subject', 'id' => $math->id, ...($grades === null ? [] : ['grades' => $grades])], 'operator' => $operator, 'value' => $value],
    ]])->assertOk()->json('data.total');

    // Every grade (default, as before): student 0 → 6.67, student 1 → 5.
    expect($count(null, 'above', 6))->toBe(1)
        ->and($count(['rule' => 'all', 'category' => null], 'above', 6.6))->toBe(1)
        ->and($count(['rule' => 'all', 'category' => null], 'above', 6.7))->toBe(0)
        // All but oral: student 0 → 5, student 1 → 8.
        ->and($count(['rule' => 'all_except_category', 'category' => 'oral'], 'above', 7))->toBe(1)
        ->and($count(['rule' => 'all_except_category', 'category' => 'oral'], 'below', 5.5))->toBe(1)
        // Average of oral only: 10 and 2.
        ->and($count(['rule' => 'category_average', 'category' => 'oral'], 'above', 9))->toBe(1)
        ->and($count(['rule' => 'category_average', 'category' => 'oral'], 'below', 3))->toBe(1)
        // Latest of the year: student 0's written 6, student 1's oral 2.
        ->and($count(['rule' => 'latest', 'category' => null], 'below', 3))->toBe(1)
        ->and($count(['rule' => 'latest', 'category' => null], 'above', 5.5))->toBe(1)
        // Latest written: student 0 → 6, student 1 → 8.
        ->and($count(['rule' => 'latest_of_category', 'category' => 'written'], 'above', 5.5))->toBe(2)
        ->and($count(['rule' => 'latest_of_category', 'category' => 'written'], 'above', 7))->toBe(1)
        // A category nobody has: no datum.
        ->and($count(['rule' => 'category_average', 'category' => 'project'], 'above', 0))->toBe(0);

    // Saved and shown with the rule that applies.
    $this->postJson('/api/v1/crossings', ['name' => 'Oral bajo', 'reading' => 'El equipo mira la oralidad.', 'conditions' => [
        ['source' => ['type' => 'subject', 'id' => $math->id, 'grades' => ['rule' => 'category_average', 'category' => 'oral']], 'operator' => 'below', 'value' => 3],
    ]])->assertCreated()
        ->assertJsonPath('data.conditions.0.source.grades', ['rule' => 'category_average', 'category' => 'oral'])
        ->assertJsonPath('data.preview.total', 1);

    // Without a rule, a subject condition says it reads every grade.
    $this->postJson('/api/v1/crossings', ['name' => 'Promedio bajo', 'reading' => 'x', 'conditions' => [
        ['source' => ['type' => 'subject', 'id' => $math->id], 'operator' => 'below', 'value' => 6],
    ]])->assertCreated()->assertJsonPath('data.conditions.0.source.grades', ['rule' => 'all', 'category' => null]);
});

it('uses the chosen grades in an indicator, and the same subject under two rules is two data', function () {
    $scenario = indicatorScenario();
    $math = mathByCategory($scenario);
    Sanctum::actingAs($scenario['director']);

    $payload = readingIndicatorPayload(0, [
        'name' => 'Escrito menos oral',
        'calculation' => 'difference',
        'inputs' => [
            ['type' => 'subject', 'id' => $math->id, 'grades' => ['rule' => 'category_average', 'category' => 'written']],
            ['type' => 'subject', 'id' => $math->id, 'grades' => ['rule' => 'category_average', 'category' => 'oral']],
        ],
        'bands' => [
            ['name' => 'Oral más alto', 'color' => 'yellow', 'max' => 0, 'meaning' => 'x'],
            ['name' => 'Escrito más alto', 'color' => 'blue', 'max' => null, 'meaning' => 'x'],
        ],
    ]);

    // Student 0: 5 − 10 = −5; student 1: 8 − 2 = 6.
    $this->postJson('/api/v1/indicators', $payload)->assertCreated()
        ->assertJsonPath('data.inputs.0.grades', ['rule' => 'category_average', 'category' => 'written'])
        ->assertJsonPath('data.inputs.1.grades', ['rule' => 'category_average', 'category' => 'oral'])
        ->assertJsonPath('data.summary.students_with_data', 2)
        ->assertJsonPath('data.summary.bands.0.count', 1)
        ->assertJsonPath('data.summary.bands.1.count', 1);

    // The very same rule twice is the same datum twice.
    $twice = $payload;
    $twice['inputs'][1] = $twice['inputs'][0];
    $this->postJson('/api/v1/indicators', $twice)->assertUnprocessable()->assertJsonValidationErrors('inputs');
});

it('reads the latest level of progress of a category for Primaria', function () {
    $scenario = indicatorScenario();
    ['school' => $school, 'a' => $a, 'studentsA' => $studentsA, 'language' => $language, 'scale' => $scale, 'psycho' => $psycho] = $scenario;
    // The scenario's assessment (10 days ago) gives student 0 level 1. An
    // older oral one gives them level 4.
    Assessment::query()->where('subject_id', $language->id)->update(['type' => 'written']);
    $oral = Assessment::factory()->for($a)->create(['school_id' => $school->id, 'subject_id' => $language->id, 'type' => 'oral', 'administered_at' => now()->subDays(40)->toDateString()]);
    AssessmentResult::factory()->for($oral)->for($studentsA[0])->create(['school_id' => $school->id, 'score' => null, 'official_scale_level_id' => $scale->levels[3]->id]);
    Sanctum::actingAs($psycho);

    $level = fn (?array $grades, int $levelIndex): int => $this->postJson('/api/v1/crossings/preview', ['conditions' => [
        ['source' => ['type' => 'subject', 'id' => $language->id, 'grades' => $grades], 'operator' => 'level', 'value' => $scale->levels[$levelIndex]->id],
    ]])->assertOk()->json('data.total');

    // Latest of the year (and every grade): level 1 for student 0 and 5.°B's first.
    expect($level(['rule' => 'latest', 'category' => null], 0))->toBe(2)
        ->and($level(null, 0))->toBe(2)
        // Latest oral: only student 0, at level 4.
        ->and($level(['rule' => 'latest_of_category', 'category' => 'oral'], 3))->toBe(1)
        ->and($level(['rule' => 'latest_of_category', 'category' => 'oral'], 0))->toBe(0);
});

it('validates the grade rule of a subject datum', function () {
    $scenario = indicatorScenario();
    $math = mathByCategory($scenario);
    Sanctum::actingAs($scenario['psycho']);

    $preview = fn (array $source) => $this->postJson('/api/v1/crossings/preview', ['conditions' => [
        ['source' => $source, 'operator' => 'below', 'value' => 5],
    ]]);

    $preview(['type' => 'subject', 'id' => $math->id, 'grades' => ['rule' => 'category_average', 'category' => null]])
        ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.source.grades.category');
    $preview(['type' => 'subject', 'id' => $math->id, 'grades' => ['rule' => 'latest', 'category' => 'oral']])
        ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.source.grades.category');
    $preview(['type' => 'subject', 'id' => $math->id, 'grades' => ['rule' => 'median', 'category' => null]])
        ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.source.grades.rule');
    $preview(['type' => 'subject', 'id' => $math->id, 'grades' => ['rule' => 'category_average', 'category' => 'exam']])
        ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.source.grades.category');
    $preview(['type' => 'subject', 'id' => $math->id, 'grades' => ['category' => 'oral']])
        ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.source.grades.rule');
    $preview(['type' => 'screening', 'id' => $scenario['reading']->id, 'grades' => ['rule' => 'all', 'category' => null]])
        ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.source.grades');

    $this->postJson('/api/v1/indicators/preview', readingIndicatorPayload(0, ['inputs' => [
        ['type' => 'subject', 'id' => $math->id, 'grades' => ['rule' => 'all_except_category', 'category' => null]],
    ]]))->assertUnprocessable()->assertJsonValidationErrors('inputs.0.grades.category');
});

it('keeps the behaviour of subject data saved before the grade rule, reversibly', function () {
    $scenario = indicatorScenario();
    $math = mathByCategory($scenario);
    $indicator = Indicator::factory()->create(['school_id' => $scenario['school']->id, 'inputs' => [['type' => 'subject', 'id' => $math->id], ['type' => 'screening', 'id' => $scenario['reading']->id]]]);
    $crossing = Crossing::factory()->create(['school_id' => $scenario['school']->id, 'conditions' => [
        ['source' => ['type' => 'subject', 'id' => $math->id], 'operator' => 'above', 'value' => 6],
    ]]);
    $migration = require database_path('migrations/2026_11_17_000001_add_grade_rule_to_subject_data.php');

    $migration->up();
    expect($indicator->fresh()->inputs)->toBe([
        ['type' => 'subject', 'id' => $math->id, 'grades' => ['rule' => 'all', 'category' => null]],
        ['type' => 'screening', 'id' => $scenario['reading']->id],
    ])->and($crossing->fresh()->conditions[0]['source']['grades'])->toBe(['rule' => 'all', 'category' => null]);

    Sanctum::actingAs($scenario['psycho']);
    $this->getJson('/api/v1/crossings')->assertOk()->assertJsonPath('data.0.preview.total', 1);

    $migration->down();
    expect($indicator->fresh()->inputs[0])->toBe(['type' => 'subject', 'id' => $math->id])
        ->and($crossing->fresh()->conditions[0]['source'])->toBe(['type' => 'subject', 'id' => $math->id]);
});

it('does not let a new screening design or a revert drop a band a crossing uses', function () {
    ['psycho' => $psycho, 'director' => $director, 'reading' => $reading] = indicatorScenario();
    Sanctum::actingAs($psycho);
    $this->postJson('/api/v1/crossings', ['name' => 'Lectura inicial', 'reading' => 'El equipo mira la lectura.', 'conditions' => [
        ['source' => ['type' => 'screening', 'id' => $reading->id], 'operator' => 'band', 'value' => 'Inicial'],
    ]])->assertCreated();

    $bands = fn (string $first): array => [
        ['name' => $first, 'color' => 'red', 'max' => 39, 'meaning' => 'x'],
        ['name' => 'En proceso', 'color' => 'yellow', 'max' => 59, 'meaning' => 'x'],
        ['name' => 'Adecuado', 'color' => 'green', 'max' => null, 'meaning' => 'x'],
    ];

    $this->postJson("/api/v1/screening-test-types/{$reading->id}/designs", ['bands' => $bands('Bajo')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bands', 'used_by_crossings'])
        ->assertJsonPath('errors.used_by_crossings', ['Lectura inicial']);
    expect($reading->fresh()->currentApprovedDesign()->bandList()[0]['name'])->toBe('Inicial');

    // Repeated band names are refused too.
    $repeated = $bands('Inicial');
    $repeated[1]['name'] = 'Inicial';
    $this->postJson("/api/v1/screening-test-types/{$reading->id}/designs", ['bands' => $repeated])
        ->assertUnprocessable()->assertJsonValidationErrors('bands.1.name');

    // A design that keeps the band is saved; then the crossing moves to a band
    // only the new design has, and going back would drop it.
    $new = $bands('Inicial');
    $new[1]['name'] = 'Intermedio';
    $design = $this->postJson("/api/v1/screening-test-types/{$reading->id}/designs", ['bands' => $new])->assertCreated()->json('data.id');
    $crossing = Crossing::query()->firstOrFail();
    $crossing->update(['conditions' => [['source' => ['type' => 'screening', 'id' => $reading->id], 'operator' => 'band', 'value' => 'Intermedio']]]);

    Sanctum::actingAs($director);
    $this->postJson("/api/v1/screening-test-designs/{$design}/revert")
        ->assertUnprocessable()->assertJsonPath('errors.used_by_crossings', ['Lectura inicial']);
    expect(ScreeningTestDesign::query()->find($design)->reverted_at)->toBeNull();
});

it('tells which crossing to change first when an indicator loses a band', function () {
    ['psycho' => $psycho, 'reading' => $reading] = indicatorScenario();
    Sanctum::actingAs($psycho);
    $indicator = $this->postJson('/api/v1/indicators', readingIndicatorPayload($reading->id))->assertCreated()->json('data.id');
    $this->postJson('/api/v1/crossings', ['name' => 'Lectura baja', 'reading' => 'x', 'conditions' => [
        ['source' => ['type' => 'indicator', 'id' => $indicator], 'operator' => 'band', 'value' => 'Bajo'],
    ]])->assertCreated();

    $renamed = readingIndicatorPayload($reading->id);
    $renamed['bands'][0]['name'] = 'Bajo rendimiento';
    $this->patchJson("/api/v1/indicators/{$indicator}", $renamed)
        ->assertUnprocessable()->assertJsonPath('errors.used_by_crossings', ['Lectura baja']);
});

it('asks which student a CSV row is when a name is repeated, offering only this school\'s roster', function () {
    ['director' => $director, 'school' => $school, 'a' => $a, 'b' => $b, 'studentsA' => $studentsA, 'studentsB' => $studentsB, 'year' => $year] = indicatorScenario();
    $studentsA[1]->update(['full_name' => 'Sofía Pérez']);
    $studentsB[0]->update(['full_name' => 'Sofia  PEREZ']);
    $studentsA[2]->update(['full_name' => 'Juan Gómez']);
    $studentsB[1]->update(['full_name' => 'Juan Gómez']);
    // Same name in another school, and a student of this school without a group this year.
    $otherSchool = School::factory()->create();
    $foreign = Student::factory()->create(['school_id' => $otherSchool->id, 'full_name' => 'Sofía Pérez']);
    $foreignGroup = Group::factory()->create(['school_id' => $otherSchool->id, 'school_year' => $year]);
    $foreignGroup->students()->attach($foreign->id, ['school_year' => $year]);
    Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Sofía Pérez']);
    $measure = ExternalMeasure::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($director);

    $rows = $this->postJson("/api/v1/external-measures/{$measure->id}/match-students", ['rows' => [
        ['student_id' => null, 'name' => 'sofía pérez', 'group' => null],
        ['student_id' => null, 'name' => 'Juan Gómez', 'group' => '5.°B'],
        ['student_id' => $studentsA[0]->id, 'name' => null, 'group' => null],
        ['student_id' => $foreign->id, 'name' => null, 'group' => null],
        ['student_id' => null, 'name' => 'Nadie Así', 'group' => null],
    ]])->assertOk()->json('data.rows');

    expect($rows[0]['status'])->toBe('ambiguous')
        ->and(collect($rows[0]['candidates'])->pluck('student_id')->all())->toBe([$studentsA[1]->id, $studentsB[0]->id])
        ->and(collect($rows[0]['candidates'])->pluck('group_name')->all())->toBe(['5.°A', '5.°B'])
        // The group in the file resolves the repeated name.
        ->and($rows[1])->toBe(['status' => 'matched', 'student_id' => $studentsB[1]->id, 'candidates' => []])
        ->and($rows[2])->toBe(['status' => 'matched', 'student_id' => $studentsA[0]->id, 'candidates' => []])
        // Another school's code is never matched nor offered.
        ->and($rows[3]['status'])->toBe('unknown')
        ->and($rows[4]['status'])->toBe('unknown');
    expect(collect($rows)->pluck('candidates')->flatten(1)->pluck('student_id')->all())->not->toContain($foreign->id);

    // Invalid input.
    $this->postJson("/api/v1/external-measures/{$measure->id}/match-students", ['rows' => [['student_id' => null, 'name' => ' ', 'group' => null]]])
        ->assertUnprocessable()->assertJsonValidationErrors('rows.0');
    $this->postJson("/api/v1/external-measures/{$measure->id}/match-students", ['rows' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('rows');
    $this->postJson("/api/v1/external-measures/{$measure->id}/match-students", ['rows' => [['student_id' => 'abc']]])
        ->assertUnprocessable()->assertJsonValidationErrors('rows.0.student_id');
});

it('closes the CSV student matching to teachers and to other schools', function () {
    ['teacher' => $teacher, 'school' => $school, 'studentsA' => $studentsA] = indicatorScenario();
    $measure = ExternalMeasure::factory()->create(['school_id' => $school->id]);
    $body = ['rows' => [['student_id' => null, 'name' => $studentsA[0]->full_name, 'group' => null]]];

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/external-measures/{$measure->id}/match-students", $body)->assertForbidden();

    $other = School::factory()->create();
    Tenancy::forget();
    Sanctum::actingAs(User::factory()->forSchool($other)->psychopedagogue()->create());
    $this->postJson("/api/v1/external-measures/{$measure->id}/match-students", $body)->assertNotFound();

    // Their own datum: this school's names are never matched nor offered.
    Tenancy::useSchool($other->id);
    $own = ExternalMeasure::factory()->create(['school_id' => $other->id]);
    Tenancy::forget();
    $this->postJson("/api/v1/external-measures/{$own->id}/match-students", $body)->assertOk()
        ->assertJsonPath('data.rows.0', ['status' => 'unknown', 'student_id' => null, 'candidates' => []]);
});
