<?php

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\CurricularFramework;
use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\OfficialScale;
use App\Models\School;
use App\Models\SchoolScaleEquivalence;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\OfficialScaleSeeder;
use Laravel\Sanctum\Sanctum;

/*
 * Screen 24 · «Escalas oficiales» and results recorded with a scale level
 * (ClickUp 1dfd3mc4bgg).
 */

beforeEach(function () {
    $this->seed(OfficialScaleSeeder::class);
});

function anepSchool(): School
{
    $school = School::factory()->create();
    $school->curricularFrameworks()->attach(
        CurricularFramework::query()->where('name', 'ANEP EBI')->firstOrFail(),
        ['active' => true],
    );

    return $school;
}

function primaryScale(): OfficialScale
{
    return OfficialScale::query()->where('key', 'anep_ebi_primary')->with('levels')->firstOrFail();
}

/**
 * @return array{teacher: User, group: Group, assessment: Assessment, student: Student}
 */
function scaleAssessment(School $school, string $level = 'primary'): array
{
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->atLevel($level)->create(['school_id' => $school->id, 'name' => '5.°A']);
    $subject = leadGroup($group, $teacher);
    $assessment = Assessment::factory()->create([
        'group_id' => $group->id, 'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
    ]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    return compact('teacher', 'group', 'assessment', 'student');
}

function configureEquivalence(School $school, array $numbers): void
{
    foreach (primaryScale()->levels as $index => $level) {
        SchoolScaleEquivalence::query()->create([
            'school_id' => $school->id,
            'official_scale_level_id' => $level->id,
            'numeric_grade' => $numbers[$index],
        ]);
    }
}

// ── Seed data ───────────────────────────────────────────────────────────────

it('seeds the ANEP Primaria scale with its levels in order and its citation', function () {
    $scale = primaryScale();

    expect($scale->curricularFramework->name)->toBe('ANEP EBI')
        ->and($scale->educationLevel->key)->toBe('primary')
        ->and($scale->citation_document)->toBe('REDE 2024, artículo 21')
        ->and($scale->citation_page)->toBe('14')
        ->and($scale->levels->pluck('name')->all())->toBe([
            'Avance destacado', 'Avance significativo', 'Avance moderado', 'Avance escaso', 'Avance mínimo',
        ]);
});

it('is idempotent when the seeder runs again', function () {
    $this->seed(OfficialScaleSeeder::class);

    expect(OfficialScale::query()->count())->toBe(3)
        ->and(primaryScale()->levels)->toHaveCount(5);
});

// ── «Escalas oficiales» tab: Dirección only, read-only ─────────────────────

it('lets direction consult the scales of its frameworks, in order, with citation', function () {
    $school = anepSchool();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $response = $this->getJson('/api/v1/official-scales')->assertOk();

    // ANEP EBI has a Primaria and a Ciclo Básico scale; Bachillerato is not
    // one of this school's frameworks.
    $response->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.framework.name', 'ANEP EBI')
        ->assertJsonPath('data.0.education_level.key', 'primary')
        ->assertJsonPath('data.0.citation.document', 'REDE 2024, artículo 21')
        ->assertJsonPath('data.0.citation.page', '14')
        ->assertJsonPath('data.0.levels.0.name', 'Avance destacado')
        ->assertJsonPath('data.0.levels.4.name', 'Avance mínimo')
        ->assertJsonPath('data.1.education_level.key', 'lower_secondary');
});

it('forbids the scales tab and its settings to teachers and psychopedagogy', function (string $role) {
    $school = anepSchool();
    Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());

    $this->getJson('/api/v1/official-scales')->assertForbidden();
    $this->getJson('/api/v1/numeric-grade-settings')->assertForbidden();
    $this->putJson('/api/v1/numeric-grade-settings', ['show_numeric_grade' => true, 'equivalences' => []])
        ->assertForbidden();
})->with(['teacher', 'psychopedagogue']);

it('requires authentication for the scales', function () {
    $this->getJson('/api/v1/official-scales')->assertUnauthorized();
});

it('exposes no route to create, change or delete a scale', function (string $method, string $uri) {
    $school = anepSchool();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $scale = primaryScale();

    $status = $this->json($method, str_replace('{id}', (string) $scale->id, $uri), ['name' => 'X'])->status();

    expect($status)->toBeIn([404, 405]);
    expect(primaryScale()->name)->toBe($scale->name);
})->with([
    ['POST', '/api/v1/official-scales'],
    ['PUT', '/api/v1/official-scales/{id}'],
    ['PATCH', '/api/v1/official-scales/{id}'],
    ['DELETE', '/api/v1/official-scales/{id}'],
    ['POST', '/api/v1/official-scales/{id}/levels'],
]);

// ── School settings: switch + own equivalence ─────────────────────────────

it('lets direction turn the number on and declare a full equivalence', function () {
    $school = anepSchool();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $levels = primaryScale()->levels;

    $this->putJson('/api/v1/numeric-grade-settings', [
        'show_numeric_grade' => true,
        'equivalences' => $levels->values()->map(fn ($level, $i) => [
            'level_id' => $level->id, 'numeric_grade' => 10 - 2 * $i,
        ])->all(),
    ])->assertOk()
        ->assertJsonPath('data.show_numeric_grade', true)
        ->assertJsonPath('data.primary_scale_id', primaryScale()->id)
        ->assertJsonCount(5, 'data.equivalences')
        ->assertJsonPath('data.equivalences.0.numeric_grade', 10);

    expect($school->refresh()->show_primary_numeric_grade)->toBeTrue()
        ->and(SchoolScaleEquivalence::query()->where('school_id', $school->id)->count())->toBe(5);

    // Clearing it: an empty equivalence removes the school's numbers.
    $this->putJson('/api/v1/numeric-grade-settings', ['show_numeric_grade' => false, 'equivalences' => []])
        ->assertOk()
        ->assertJsonPath('data.equivalences', []);
    expect(SchoolScaleEquivalence::query()->where('school_id', $school->id)->count())->toBe(0);
});

it('rejects an equivalence outside 1..10, partial, repeated or for another scale', function (Closure $rows, string $error) {
    $school = anepSchool();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->putJson('/api/v1/numeric-grade-settings', [
        'show_numeric_grade' => true,
        'equivalences' => $rows(primaryScale()->levels->values()),
    ])->assertUnprocessable()->assertJsonValidationErrorFor($error);

    expect(SchoolScaleEquivalence::query()->count())->toBe(0);
})->with([
    'number above 10' => [fn ($levels) => $levels->map(fn ($l) => ['level_id' => $l->id, 'numeric_grade' => 11])->all(), 'equivalences.0.numeric_grade'],
    'number below 1' => [fn ($levels) => $levels->map(fn ($l) => ['level_id' => $l->id, 'numeric_grade' => 0])->all(), 'equivalences.0.numeric_grade'],
    'decimal number' => [fn ($levels) => $levels->map(fn ($l) => ['level_id' => $l->id, 'numeric_grade' => 7.5])->all(), 'equivalences.0.numeric_grade'],
    'missing levels' => [fn ($levels) => [['level_id' => $levels[0]->id, 'numeric_grade' => 10]], 'equivalences'],
    'repeated level' => [fn ($levels) => $levels->map(fn ($l) => ['level_id' => $levels[0]->id, 'numeric_grade' => 5])->all(), 'equivalences.1.level_id'],
    'level of another scale' => [fn ($levels) => OfficialScale::query()->where('key', 'anep_upper_secondary')->firstOrFail()
        ->levels->map(fn ($l) => ['level_id' => $l->id, 'numeric_grade' => 5])->all(), 'equivalences.0.level_id'],
]);

it('rejects an equivalence when the school has no Primaria scale', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->putJson('/api/v1/numeric-grade-settings', [
        'show_numeric_grade' => true,
        'equivalences' => [['level_id' => primaryScale()->levels[0]->id, 'numeric_grade' => 10]],
    ])->assertUnprocessable()->assertJsonValidationErrorFor('equivalences.0.level_id');
});

// ── Loading results with a level ───────────────────────────────────────────

it('records a 5.°A result with a level of the Primaria scale', function () {
    $school = anepSchool();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    Sanctum::actingAs($teacher);
    $level = primaryScale()->levels[1];

    $this->getJson("/api/v1/assessments/{$assessment->id}/result-scale")
        ->assertOk()
        ->assertJsonPath('data.mode', 'scale')
        ->assertJsonPath('data.scale.levels.0.name', 'Avance destacado')
        ->assertJsonPath('data.scale.citation.page', '14')
        ->assertJsonPath('data.numeric_grade.shown', false);

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'scale_level_id' => $level->id, 'feedback' => 'Bien']],
    ])->assertOk()
        ->assertJsonPath('data.0.score', null)
        ->assertJsonPath('data.0.scale_level.name', 'Avance significativo')
        ->assertJsonPath('data.0.numeric_grade', null);

    $result = AssessmentResult::query()->firstOrFail();
    expect($result->score)->toBeNull()
        ->and($result->official_scale_level_id)->toBe($level->id);
});

it('rejects a level that does not belong to the group\'s scale, a missing level or a score', function (Closure $row, string $error) {
    $school = anepSchool();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id] + $row()],
    ])->assertUnprocessable()->assertJsonValidationErrorFor($error);

    $this->assertDatabaseCount('assessment_results', 0);
})->with([
    'level of Ciclo Básico' => [fn () => ['scale_level_id' => OfficialScale::query()->where('key', 'anep_ebi_lower_secondary')->firstOrFail()->levels[0]->id], 'results.0.scale_level_id'],
    'no level' => [fn () => [], 'results.0.scale_level_id'],
    'a decimal score' => [fn () => ['score' => 8, 'scale_level_id' => primaryScale()->levels[0]->id], 'results.0.score'],
]);

it('keeps numeric results for groups outside Primaria', function () {
    $school = anepSchool();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school, 'lower_secondary');
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/assessments/{$assessment->id}/result-scale")
        ->assertOk()->assertJsonPath('data.mode', 'score');

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'score' => 7.5]],
    ])->assertOk()->assertJsonPath('data.0.scale_level', null);

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'scale_level_id' => primaryScale()->levels[0]->id]],
    ])->assertUnprocessable()->assertJsonValidationErrorFor('results.0.score');
});

it('keeps numeric results for a Primaria group whose framework has no official scale', function () {
    $school = School::factory()->create();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'score' => 9]],
    ])->assertOk();
});

it('uses the group\'s own framework over the school\'s', function () {
    $school = anepSchool();
    ['teacher' => $teacher, 'group' => $group, 'assessment' => $assessment] = scaleAssessment($school);
    $group->curricularFrameworks()->attach(CurricularFramework::query()->where('name', 'Cambridge Primary')->firstOrFail());
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/assessments/{$assessment->id}/result-scale")
        ->assertOk()->assertJsonPath('data.mode', 'score');
});

// ── Number display rules ───────────────────────────────────────────────────

it('accepts the teacher\'s number only when the number is shown and the school has no equivalence', function () {
    $school = anepSchool();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    Sanctum::actingAs($teacher);
    $level = primaryScale()->levels[0];
    $post = fn (array $extra) => $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'scale_level_id' => $level->id] + $extra],
    ]);

    // Switch off: no number can be written.
    $post(['numeric_grade' => 9])->assertUnprocessable()->assertJsonValidationErrorFor('results.0.numeric_grade');

    // Switch on, no equivalence: the teacher writes it (1..10, whole).
    $school->update(['show_primary_numeric_grade' => true]);
    $this->getJson("/api/v1/assessments/{$assessment->id}/result-scale")
        ->assertJsonPath('data.numeric_grade.shown', true)
        ->assertJsonPath('data.numeric_grade.source', 'teacher');
    $post(['numeric_grade' => 11])->assertUnprocessable()->assertJsonValidationErrorFor('results.0.numeric_grade');
    $post(['numeric_grade' => 9])->assertOk()
        ->assertJsonPath('data.0.numeric_grade', 9)
        ->assertJsonPath('data.0.numeric_grade_source', 'teacher');

    // Switch on, with the school's equivalence: the number is the school's.
    configureEquivalence($school, [10, 8, 6, 4, 2]);
    $post(['numeric_grade' => 9])->assertUnprocessable()->assertJsonValidationErrorFor('results.0.numeric_grade');
    $this->getJson("/api/v1/assessments/{$assessment->id}/results")
        ->assertJsonPath('data.0.numeric_grade', 10)
        ->assertJsonPath('data.0.numeric_grade_source', 'school');
});

it('shows no number at all when the switch is off, even with an equivalence or a teacher number', function () {
    $school = anepSchool();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    (new AssessmentResult)->forceFill([
        'assessment_id' => $assessment->id,
        'student_id' => $student->id,
        'official_scale_level_id' => primaryScale()->levels[2]->id,
        'numeric_grade' => 6,
        'created_by_id' => $teacher->id,
        'school_id' => $school->id,
    ])->save();
    configureEquivalence($school, [10, 8, 6, 4, 2]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/assessments/{$assessment->id}/results")
        ->assertOk()
        ->assertJsonPath('data.0.scale_level.name', 'Avance moderado')
        ->assertJsonPath('data.0.numeric_grade', null)
        ->assertJsonPath('data.0.numeric_grade_source', null);
});

it('returns no number when the switch is on, there is no equivalence and no teacher number (the screen shows «sin nota»)', function () {
    $school = anepSchool();
    $school->update(['show_primary_numeric_grade' => true]);
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'scale_level_id' => primaryScale()->levels[3]->id]],
    ])->assertOk()
        ->assertJsonPath('data.0.scale_level.name', 'Avance escaso')
        ->assertJsonPath('data.0.numeric_grade', null)
        ->assertJsonPath('data.0.numeric_grade_source', null);
});

it('shows the level and the allowed number in the student\'s grade list, and keeps it off the numeric line', function () {
    $school = anepSchool();
    $school->update(['show_primary_numeric_grade' => true]);
    configureEquivalence($school, [10, 8, 6, 4, 2]);
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'scale_level_id' => primaryScale()->levels[1]->id]],
    ])->assertOk();

    $this->getJson("/api/v1/students/{$student->id}/tracking")
        ->assertOk()
        ->assertJsonPath('data.grades.0.scale_level_name', 'Avance significativo')
        ->assertJsonPath('data.grades.0.numeric_grade', 8)
        ->assertJsonPath('data.grades.0.numeric_grade_source', 'school')
        ->assertJsonPath('data.grades.0.numeric_grade_missing', false)
        ->assertJsonPath('data.grades.0.score', null);

    // Without the school's equivalence and no teacher number: «sin nota».
    SchoolScaleEquivalence::query()->withoutGlobalScopes()->where('school_id', $school->id)->delete();
    $this->getJson("/api/v1/students/{$student->id}/tracking")
        ->assertJsonPath('data.grades.0.numeric_grade', null)
        ->assertJsonPath('data.grades.0.numeric_grade_missing', true);

    // PA-088: a level is never placed on the «Nota de 1 a 10» line.
    $this->getJson("/api/v1/students/{$student->id}/performance-timeline")
        ->assertOk()->assertJsonCount(0, 'results');
    $this->getJson("/api/v1/groups/{$assessment->group_id}/performance-timeline")
        ->assertOk()->assertJsonCount(0, 'results');
});

// ── Tenant isolation ───────────────────────────────────────────────────────

it('isolates scales, settings and result scales between schools', function () {
    $schoolA = anepSchool();
    $schoolB = School::factory()->create();
    configureEquivalence($schoolA, [10, 8, 6, 4, 2]);
    $schoolA->update(['show_primary_numeric_grade' => true]);
    ['assessment' => $assessmentA] = scaleAssessment($schoolA);

    $directorB = User::factory()->forSchool($schoolB)->director()->create();
    Sanctum::actingAs($directorB);

    // B uses no framework with a scale: it sees none of A's.
    $this->getJson('/api/v1/official-scales')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/numeric-grade-settings')->assertOk()
        ->assertJsonPath('data.show_numeric_grade', false)
        ->assertJsonPath('data.equivalences', []);

    // A's assessment does not exist for B.
    $this->getJson("/api/v1/assessments/{$assessmentA->id}/result-scale")->assertNotFound();
    $this->getJson("/api/v1/assessments/{$assessmentA->id}/results")->assertNotFound();

    // B saving its settings never touches A's equivalence.
    $this->putJson('/api/v1/numeric-grade-settings', ['show_numeric_grade' => true, 'equivalences' => []])->assertOk();
    expect(SchoolScaleEquivalence::query()->withoutGlobalScopes()->where('school_id', $schoolA->id)->count())->toBe(5);
});

it('does not let a teacher of another school load results with a level', function () {
    $schoolA = anepSchool();
    ['assessment' => $assessmentA, 'student' => $student] = scaleAssessment($schoolA);
    $schoolB = anepSchool();
    Sanctum::actingAs(User::factory()->forSchool($schoolB)->teacher()->create());

    $this->postJson("/api/v1/assessments/{$assessmentA->id}/results", [
        'results' => [['student_id' => $student->id, 'scale_level_id' => primaryScale()->levels[0]->id]],
    ])->assertNotFound();
});

// ── PO answers of 12/11 ─────────────────────────────────────────────────────

it('shows the official ANEP band of each level as reference data', function () {
    $school = anepSchool();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->getJson('/api/v1/official-scales')->assertOk()
        ->assertJsonPath('data.0.levels.0.official_band', '9–10')
        ->assertJsonPath('data.0.levels.4.official_band', '1–2');
});

it('keeps the teacher\'s number when the switch goes off or the school configures an equivalence', function () {
    $school = anepSchool();
    $school->update(['show_primary_numeric_grade' => true]);
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    Sanctum::actingAs($teacher);
    $url = "/api/v1/assessments/{$assessment->id}/results";
    $level = primaryScale()->levels[0]->id;

    $this->postJson($url, ['results' => [['student_id' => $student->id, 'scale_level_id' => $level, 'numeric_grade' => 9]]])
        ->assertOk();

    // Switch off, the level saved again (the number can't be sent now).
    $school->update(['show_primary_numeric_grade' => false]);
    $this->postJson($url, ['results' => [['student_id' => $student->id, 'scale_level_id' => $level, 'feedback' => 'x']]])
        ->assertOk()->assertJsonPath('data.0.numeric_grade', null);

    // Back on: the 9 is still there.
    $school->update(['show_primary_numeric_grade' => true]);
    $this->getJson($url)->assertJsonPath('data.0.numeric_grade', 9)
        ->assertJsonPath('data.0.numeric_grade_source', 'teacher');

    // An equivalence configured, the level saved again, the equivalence removed.
    configureEquivalence($school, [10, 8, 6, 4, 2]);
    $this->postJson($url, ['results' => [['student_id' => $student->id, 'scale_level_id' => $level]]])
        ->assertOk()->assertJsonPath('data.0.numeric_grade', 10);
    SchoolScaleEquivalence::query()->withoutGlobalScopes()->where('school_id', $school->id)->delete();
    $this->getJson($url)->assertJsonPath('data.0.numeric_grade', 9);
});

it('keeps a result recorded as a score visible and only replaces it explicitly', function () {
    $school = anepSchool();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    (new AssessmentResult)->forceFill([
        'assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => 7,
        'created_by_id' => $teacher->id, 'school_id' => $school->id,
    ])->save();
    Sanctum::actingAs($teacher);
    $url = "/api/v1/assessments/{$assessment->id}/results";
    $level = primaryScale()->levels[1]->id;

    $this->getJson($url)->assertJsonPath('data.0.score', '7.00')->assertJsonPath('data.0.scale_level', null);

    $this->postJson($url, ['results' => [['student_id' => $student->id, 'scale_level_id' => $level]]])
        ->assertUnprocessable()->assertJsonValidationErrorFor('results.0.scale_level_id');
    $this->assertDatabaseHas('assessment_results', ['student_id' => $student->id, 'score' => 7, 'official_scale_level_id' => null]);

    $this->postJson($url, ['results' => [['student_id' => $student->id, 'scale_level_id' => $level, 'replace_previous' => true]]])
        ->assertOk()
        ->assertJsonPath('data.0.score', null)
        ->assertJsonPath('data.0.scale_level.id', $level);
});

it('keeps a result recorded as a level visible in a group that records scores now', function () {
    $school = anepSchool();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student, 'group' => $group] = scaleAssessment($school);
    (new AssessmentResult)->forceFill([
        'assessment_id' => $assessment->id, 'student_id' => $student->id,
        'official_scale_level_id' => primaryScale()->levels[0]->id,
        'created_by_id' => $teacher->id, 'school_id' => $school->id,
    ])->save();
    // The group stops being Primaria (its level was corrected).
    $group->update(['education_level_id' => EducationLevel::query()->where('key', 'lower_secondary')->value('id')]);
    Sanctum::actingAs($teacher);
    $url = "/api/v1/assessments/{$assessment->id}/results";

    $this->getJson("/api/v1/assessments/{$assessment->id}/result-scale")->assertJsonPath('data.mode', 'score');
    $this->getJson($url)->assertJsonPath('data.0.scale_level.name', 'Avance destacado');

    $this->postJson($url, ['results' => [['student_id' => $student->id, 'score' => 8]]])
        ->assertUnprocessable()->assertJsonValidationErrorFor('results.0.score');
    $this->postJson($url, ['results' => [['student_id' => $student->id, 'score' => 8, 'replace_previous' => true]]])
        ->assertOk()->assertJsonPath('data.0.score', '8.00')->assertJsonPath('data.0.scale_level', null);
});

/**
 * A second valid Primaria scale for the group, from another framework.
 */
function secondPrimaryScale(Group $group): OfficialScale
{
    $framework = CurricularFramework::query()->firstOrCreate(['name' => 'IB PYP']);
    $group->curricularFrameworks()->attach([
        CurricularFramework::query()->where('name', 'ANEP EBI')->value('id'),
        $framework->id,
    ]);
    $scale = OfficialScale::query()->create([
        'key' => 'ib_pyp_primary',
        'curricular_framework_id' => $framework->id,
        'education_level_id' => EducationLevel::query()->where('key', 'primary')->value('id'),
        'name' => 'PYP · niveles',
        'issuing_body' => 'IB',
        'citation_document' => 'Texto de muestra',
        'citation_page' => '1',
    ]);
    foreach (['Excelente', 'Bueno', 'En desarrollo'] as $index => $name) {
        $scale->levels()->create(['position' => $index + 1, 'name' => $name]);
    }

    return $scale->load('levels');
}

it('makes the teacher choose the scale first when the group has two valid ones, and keeps it', function () {
    $school = anepSchool();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student, 'group' => $group] = scaleAssessment($school);
    $pyp = secondPrimaryScale($group);
    Sanctum::actingAs($teacher);
    $scaleUrl = "/api/v1/assessments/{$assessment->id}/result-scale";
    $resultsUrl = "/api/v1/assessments/{$assessment->id}/results";

    $this->getJson($scaleUrl)->assertOk()
        ->assertJsonPath('data.mode', 'choose')
        ->assertJsonCount(2, 'data.choices')
        ->assertJsonPath('data.choices.0.framework.name', 'ANEP EBI')
        ->assertJsonPath('data.choices.1.framework.name', 'IB PYP');

    // Nothing is recorded before choosing.
    $this->postJson($resultsUrl, ['results' => [['student_id' => $student->id, 'scale_level_id' => $pyp->levels[0]->id]]])
        ->assertUnprocessable();

    // A scale that is not valid for the group is rejected.
    $this->putJson($scaleUrl, ['official_scale_id' => OfficialScale::query()->where('key', 'anep_upper_secondary')->value('id')])
        ->assertUnprocessable()->assertJsonValidationErrorFor('official_scale_id');

    $this->putJson($scaleUrl, ['official_scale_id' => $pyp->id])->assertOk()
        ->assertJsonPath('data.mode', 'scale')
        ->assertJsonPath('data.scale.framework.name', 'IB PYP');
    expect($assessment->refresh()->official_scale_id)->toBe($pyp->id);

    // Only PYP levels now.
    $this->postJson($resultsUrl, ['results' => [['student_id' => $student->id, 'scale_level_id' => primaryScale()->levels[0]->id]]])
        ->assertUnprocessable();
    $this->postJson($resultsUrl, ['results' => [['student_id' => $student->id, 'scale_level_id' => $pyp->levels[0]->id]]])
        ->assertOk();

    // With results recorded, the scale can't change any more.
    $this->putJson($scaleUrl, ['official_scale_id' => primaryScale()->id])
        ->assertUnprocessable()->assertJsonValidationErrorFor('official_scale_id');

    // And the group losing a framework doesn't re-label what was recorded.
    $group->curricularFrameworks()->detach($pyp->curricular_framework_id);
    $this->getJson($scaleUrl)->assertJsonPath('data.scale.framework.name', 'IB PYP');
});

it('only lets the owning teacher choose the scale', function (string $role) {
    $school = anepSchool();
    ['assessment' => $assessment, 'group' => $group] = scaleAssessment($school);
    $pyp = secondPrimaryScale($group);
    Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());

    $this->putJson("/api/v1/assessments/{$assessment->id}/result-scale", ['official_scale_id' => $pyp->id])
        ->assertForbidden();
    expect($assessment->refresh()->official_scale_id)->toBeNull();
})->with(['teacher', 'director', 'psychopedagogue']);

it('returns 404 when a teacher of another school chooses the scale', function () {
    $school = anepSchool();
    ['assessment' => $assessment, 'group' => $group] = scaleAssessment($school);
    $pyp = secondPrimaryScale($group);
    Sanctum::actingAs(User::factory()->forSchool(anepSchool())->teacher()->create());

    $this->putJson("/api/v1/assessments/{$assessment->id}/result-scale", ['official_scale_id' => $pyp->id])
        ->assertNotFound();
});

it('keeps the only valid scale with the assessment on its first result', function () {
    $school = anepSchool();
    ['teacher' => $teacher, 'assessment' => $assessment, 'student' => $student] = scaleAssessment($school);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/assessments/{$assessment->id}/results", [
        'results' => [['student_id' => $student->id, 'scale_level_id' => primaryScale()->levels[0]->id]],
    ])->assertOk();

    expect($assessment->refresh()->official_scale_id)->toBe(primaryScale()->id);
});
