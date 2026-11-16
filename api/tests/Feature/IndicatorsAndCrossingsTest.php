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
