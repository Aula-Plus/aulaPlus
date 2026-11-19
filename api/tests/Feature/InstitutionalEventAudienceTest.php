<?php

use App\Enums\PlannerFamily;
use App\Models\CalendarEvent;
use App\Models\CurricularFramework;
use App\Models\CurricularOffering;
use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\School;
use App\Models\SchoolGrade;
use App\Models\Subject;
use App\Models\User;
use App\Services\Calendar\EventAudience;
use Laravel\Sanctum\Sanctum;

/**
 * Who an institutional event concerns (screen 21, P21-DEC-05/108; ClickUp
 * 1dfd3mc55zp): the whole school, levels, generations, the groups of a
 * program within a generation, or a combination. Each person's calendar shows
 * the ones of their groups; the full calendar shows them all.
 */
function audienceLevelId(string $key): int
{
    return (int) EducationLevel::query()->where('key', $key)->value('id');
}

function audienceGradeId(string $key): int
{
    return (int) SchoolGrade::query()->where('key', $key)->value('id');
}

/**
 * @return array{school: School, director: User, cambridge: CurricularFramework, anep: CurricularFramework, ib: CurricularFramework}
 */
function audienceSchool(): array
{
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    // Catalog names are unique; a test may build two schools.
    $suffix = ' '.$school->id;
    $anep = CurricularFramework::factory()->anep()->create(['name' => 'ANEP EBI'.$suffix, 'education_levels' => ['primary']]);
    $cambridge = CurricularFramework::factory()->family(PlannerFamily::Cambridge)->create(['name' => 'Cambridge Primary'.$suffix, 'education_levels' => ['primary']]);
    $ib = CurricularFramework::factory()->family(PlannerFamily::IbMyp)->create(['name' => 'IB MYP'.$suffix, 'education_levels' => ['lower_secondary']]);
    $school->curricularFrameworks()->attach([
        $anep->id => ['active' => true, 'mandatory' => true],
        $cambridge->id => ['active' => true, 'mandatory' => false],
        $ib->id => ['active' => true, 'mandatory' => false],
    ]);

    return ['school' => $school, 'director' => $director, 'cambridge' => $cambridge, 'anep' => $anep, 'ib' => $ib];
}

/** A teacher of one group, at a level and grade, optionally following a program. */
function audienceTeacher(School $school, string $level, ?string $grade, ?CurricularFramework $framework = null, int $year = 2026): User
{
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->atLevel($level)->create([
        'school_id' => $school->id,
        'school_grade_id' => $grade ? audienceGradeId($grade) : null,
        'school_year' => $year,
    ]);
    $subject = Subject::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher, $subject);
    if ($framework) {
        CurricularOffering::query()->forceCreate([
            'school_id' => $school->id,
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'curricular_framework_id' => $framework->id,
        ]);
    }

    return $teacher;
}

function institutionalTitles(string $scope = 'mine'): array
{
    $items = test()->getJson('/api/v1/calendar?from=2026-12-01&to=2026-12-31&types[]=institutional&scope='.$scope)
        ->assertOk()
        ->json('data');

    return collect($items)->pluck('title')->sort()->values()->all();
}

it('creates an event for a combination of targets and returns its audience', function () {
    ['director' => $director, 'cambridge' => $cambridge] = audienceSchool();
    Sanctum::actingAs($director);

    $response = $this->postJson('/api/v1/calendar-events', [
        'title' => 'Feria de ciencias',
        'date' => '2026-12-10',
        'audience' => [
            ['level_id' => audienceLevelId('lower_secondary')],
            ['level_id' => audienceLevelId('upper_secondary')],
            ['grade_id' => audienceGradeId('primary_6')],
            ['grade_id' => audienceGradeId('primary_3'), 'framework_id' => $cambridge->id],
        ],
    ])->assertCreated();

    expect($response->json('data.audience'))->toHaveCount(4)
        ->and($response->json('data.audience.0.level.key'))->toBe('lower_secondary')
        ->and($response->json('data.audience.2.grade.key'))->toBe('primary_6')
        ->and($response->json('data.audience.3'))->toMatchArray([
            'level' => null,
            'grade' => ['id' => audienceGradeId('primary_3'), 'key' => 'primary_3', 'label' => SchoolGrade::find(audienceGradeId('primary_3'))->label],
            'framework' => ['id' => $cambridge->id, 'name' => $cambridge->name],
        ]);
    expect(CalendarEvent::sole()->audiences)->toHaveCount(4);
});

it('defaults to the whole school, keeps the audience on edit when not sent and replaces it when sent', function () {
    ['director' => $director] = audienceSchool();
    Sanctum::actingAs($director);

    $id = $this->postJson('/api/v1/calendar-events', ['title' => 'Acto', 'date' => '2026-12-15'])
        ->assertCreated()
        ->assertJsonPath('data.audience', [])
        ->json('data.id');

    $this->patchJson("/api/v1/calendar-events/{$id}", ['audience' => [['level_id' => audienceLevelId('primary')]]])
        ->assertOk()
        ->assertJsonPath('data.audience.0.level.key', 'primary');

    $this->patchJson("/api/v1/calendar-events/{$id}", ['title' => 'Acto de fin de año'])
        ->assertOk()
        ->assertJsonCount(1, 'data.audience');

    $this->patchJson("/api/v1/calendar-events/{$id}", ['audience' => []])
        ->assertOk()
        ->assertJsonPath('data.audience', []);
});

it('validates the audience targets', function (mixed $audience, string $error) {
    ['director' => $director, 'cambridge' => $cambridge, 'anep' => $anep, 'ib' => $ib] = audienceSchool();
    Sanctum::actingAs($director);

    $audience = ! is_array($audience) ? $audience : array_map(fn (array $target) => array_map(fn ($value) => match ($value) {
        'cambridge' => $cambridge->id,
        'anep' => $anep->id,
        'ib' => $ib->id,
        default => is_string($value) ? (str_contains($value, '_') && SchoolGrade::query()->where('key', $value)->exists() ? audienceGradeId($value) : audienceLevelId($value)) : $value,
    }, $target), $audience);

    $this->postJson('/api/v1/calendar-events', ['title' => 'X', 'date' => '2026-12-15', 'audience' => $audience])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);
    expect(CalendarEvent::count())->toBe(0);
})->with([
    'unknown level' => [[['level_id' => 999999]], 'audience.0.level_id'],
    'unknown grade' => [[['grade_id' => 999999]], 'audience.0.grade_id'],
    'empty target' => [[[]], 'audience.0'],
    'level and grade at once' => [[['level_id' => 'primary', 'grade_id' => 'primary_3']], 'audience.0'],
    'program without grade' => [[['level_id' => 'primary', 'framework_id' => 'cambridge']], 'audience.0.framework_id'],
    'mandatory regime as program' => [[['grade_id' => 'primary_3', 'framework_id' => 'anep']], 'audience.0.framework_id'],
    'program of another level' => [[['grade_id' => 'primary_3', 'framework_id' => 'ib']], 'audience.0.framework_id'],
    'repeated target' => [[['level_id' => 'primary'], ['level_id' => 'primary']], 'audience.1'],
    'unknown key' => [[['level_id' => 'primary', 'group_id' => 3]], 'audience.0'],
    'not a list' => ['primary', 'audience'],
]);

it('rejects a program the school does not offer, even if another school does (tenant isolation)', function () {
    ['director' => $director] = audienceSchool();
    $other = School::factory()->create();
    $foreign = CurricularFramework::factory()->family(PlannerFamily::Cambridge)->create(['name' => 'Cambridge Lower Secondary', 'education_levels' => null]);
    $other->curricularFrameworks()->attach($foreign->id, ['active' => true, 'mandatory' => false]);
    Sanctum::actingAs($director);

    $this->postJson('/api/v1/calendar-events', [
        'title' => 'X', 'date' => '2026-12-15',
        'audience' => [['grade_id' => audienceGradeId('grade_8'), 'framework_id' => $foreign->id]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['audience.0.framework_id']);
});

it('forbids non-directors from setting an audience or reading the options', function () {
    ['school' => $school] = audienceSchool();

    foreach (['teacher', 'psychopedagogue'] as $role) {
        Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());
        $this->getJson('/api/v1/calendar-events/audience-options')->assertForbidden();
        $this->postJson('/api/v1/calendar-events', [
            'title' => 'X', 'date' => '2026-12-15', 'audience' => [['level_id' => audienceLevelId('primary')]],
        ])->assertForbidden();
    }
});

it('offers Direction the levels with their grades and the school\'s non-mandatory programs', function () {
    ['director' => $director, 'cambridge' => $cambridge, 'ib' => $ib] = audienceSchool();
    // Another school's program never shows up.
    School::factory()->create()->curricularFrameworks()->attach(
        CurricularFramework::factory()->create(['name' => 'Otro'])->id,
        ['active' => true, 'mandatory' => false],
    );
    Sanctum::actingAs($director);

    $data = $this->getJson('/api/v1/calendar-events/audience-options')->assertOk()->json('data');

    expect(array_column($data['levels'], 'key'))->toBe(['initial', 'primary', 'lower_secondary', 'upper_secondary'])
        ->and(array_column($data['levels'][1]['grades'], 'key'))->toContain('primary_3')
        ->and(array_column($data['frameworks'], 'id'))->toBe([$cambridge->id, $ib->id]);
});

it('shows each teacher only the events that concern their groups, and all of them in the full calendar', function () {
    ['school' => $school, 'director' => $director, 'cambridge' => $cambridge] = audienceSchool();
    $primary3Cambridge = audienceTeacher($school, 'primary', 'primary_3', $cambridge);
    $primary3 = audienceTeacher($school, 'primary', 'primary_3');
    $secondary = audienceTeacher($school, 'lower_secondary', 'grade_8');
    $noGroups = User::factory()->forSchool($school)->teacher()->create();

    Sanctum::actingAs($director);
    $events = [
        'Todo el colegio' => [],
        'Primaria' => [['level_id' => audienceLevelId('primary')]],
        'Secundaria' => [['level_id' => audienceLevelId('lower_secondary')], ['level_id' => audienceLevelId('upper_secondary')]],
        'Tercero' => [['grade_id' => audienceGradeId('primary_3')]],
        'Cambridge en tercero' => [['grade_id' => audienceGradeId('primary_3'), 'framework_id' => $cambridge->id]],
        'Sexto o Bachillerato' => [['grade_id' => audienceGradeId('primary_6')], ['level_id' => audienceLevelId('upper_secondary')]],
    ];
    foreach ($events as $title => $audience) {
        $this->postJson('/api/v1/calendar-events', ['title' => $title, 'date' => '2026-12-10', 'audience' => $audience])->assertCreated();
    }
    $all = collect(array_keys($events))->sort()->values()->all();

    Sanctum::actingAs($primary3Cambridge);
    expect(institutionalTitles())->toBe(['Cambridge en tercero', 'Primaria', 'Tercero', 'Todo el colegio'])
        ->and(institutionalTitles('school'))->toBe($all);

    Sanctum::actingAs($primary3);
    expect(institutionalTitles())->toBe(['Primaria', 'Tercero', 'Todo el colegio']);

    Sanctum::actingAs($secondary);
    expect(institutionalTitles())->toBe(['Secundaria', 'Todo el colegio']);

    Sanctum::actingAs($noGroups);
    expect(institutionalTitles())->toBe(['Todo el colegio'])
        ->and(institutionalTitles('school'))->toBe($all);

    // The full calendar marks what is not theirs.
    $items = collect($this->getJson('/api/v1/calendar?from=2026-12-01&to=2026-12-31&scope=school')->json('data'))->keyBy('title');
    expect($items['Todo el colegio']['for_me'])->toBeTrue()
        ->and($items['Primaria']['for_me'])->toBeFalse()
        ->and($items['Primaria']['can_edit'])->toBeFalse();
});

it('only counts a teacher\'s groups of the event\'s school year', function () {
    ['school' => $school, 'director' => $director] = audienceSchool();
    $lastYear = audienceTeacher($school, 'primary', 'primary_3', year: 2025);

    Sanctum::actingAs($director);
    $this->postJson('/api/v1/calendar-events', ['title' => 'Tercero', 'date' => '2026-12-10', 'audience' => [['grade_id' => audienceGradeId('primary_3')]]])->assertCreated();

    Sanctum::actingAs($lastYear);
    expect(institutionalTitles())->toBe([]);
});

it('shows Psychopedagogy and Direction every institutional event (school-wide roles)', function () {
    ['school' => $school, 'director' => $director] = audienceSchool();
    Sanctum::actingAs($director);
    $this->postJson('/api/v1/calendar-events', ['title' => 'Bachillerato', 'date' => '2026-12-10', 'audience' => [['level_id' => audienceLevelId('upper_secondary')]]])->assertCreated();

    expect(institutionalTitles())->toBe(['Bachillerato']);

    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());
    $items = $this->getJson('/api/v1/calendar?from=2026-12-01&to=2026-12-31')->json('data');
    expect(array_column($items, 'title'))->toBe(['Bachillerato'])
        ->and($items[0]['for_me'])->toBeTrue();
});

it('never shows another school\'s events, not even in the full calendar', function () {
    ['director' => $director] = audienceSchool();
    $other = audienceSchool();
    Sanctum::actingAs($other['director']);
    $this->postJson('/api/v1/calendar-events', ['title' => 'Ajeno', 'date' => '2026-12-10'])->assertCreated();

    Sanctum::actingAs($director);
    expect(institutionalTitles('school'))->toBe([]);
});

it('validates the calendar scope', function () {
    ['director' => $director] = audienceSchool();
    Sanctum::actingAs($director);

    $this->getJson('/api/v1/calendar?from=2026-12-01&to=2026-12-31&scope=everything')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scope']);
});

it('matches a program target only in groups whose offering follows it', function () {
    ['school' => $school, 'cambridge' => $cambridge] = audienceSchool();
    $event = CalendarEvent::factory()->create(['school_id' => $school->id, 'start_at' => '2026-12-10 12:00:00']);
    app(EventAudience::class)->replace($event, [['grade_id' => audienceGradeId('primary_3'), 'framework_id' => $cambridge->id]]);
    $event->load('audiences');
    $audience = app(EventAudience::class);

    expect($audience->concerns($event, $audience->groupsOf(audienceTeacher($school, 'primary', 'primary_3', $cambridge))))->toBeTrue()
        ->and($audience->concerns($event, $audience->groupsOf(audienceTeacher($school, 'primary', 'primary_4', $cambridge))))->toBeFalse()
        ->and($audience->concerns($event, $audience->groupsOf(audienceTeacher($school, 'primary', 'primary_3'))))->toBeFalse();
});
