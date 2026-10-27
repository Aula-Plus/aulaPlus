<?php

use App\Models\AuditLog;
use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\School;
use App\Models\SchoolGrade;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestResult;
use App\Models\ScreeningTestType;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3t4qjh: the level of each group is a choosable datum.

function levelId(string $key): int
{
    return EducationLevel::query()->where('key', $key)->value('id');
}

function gradeId(string $key): int
{
    return SchoolGrade::query()->where('key', $key)->value('id');
}

it('lists the four levels with stable keys, in order, each with the grades it suggests', function () {
    $school = School::factory()->create();
    Group::factory()->withoutLevel()->count(2)->create(['school_id' => $school->id]);
    Group::factory()->create(['school_id' => $school->id]);
    // Another school's group without level never counts here.
    Group::factory()->withoutLevel()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $response = $this->getJson('/api/v1/education-levels')->assertOk();

    expect(collect($response->json('data'))->pluck('label', 'key')->all())->toBe([
        'initial' => 'Inicial',
        'primary' => 'Primaria',
        'lower_secondary' => 'Ciclo Básico',
        'upper_secondary' => 'Bachillerato',
    ]);
    $lowerSecondary = collect($response->json('data'))->firstWhere('key', 'lower_secondary');
    expect(collect($lowerSecondary['grades'])->pluck('label')->all())->toBe(['7°', '8°', '9°']);
    $response->assertJsonPath('groups_without_level', 2);
});

it('requires a level when creating a group and keeps the grade optional and separate', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->postJson('/api/groups', ['name' => '2° A', 'school_year' => 2026])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('education_level_id');

    // A workshop: level, no grade.
    $this->postJson('/api/groups', [
        'name' => 'Taller de robótica',
        'school_year' => 2026,
        'education_level_id' => levelId('lower_secondary'),
    ])->assertCreated()
        ->assertJsonPath('data.education_level.key', 'lower_secondary')
        ->assertJsonPath('data.school_grade', null);

    // The grade suggests a level but never forces it: 9° stored with Bachillerato.
    $this->postJson('/api/groups', [
        'name' => 'Mixto',
        'school_year' => 2026,
        'education_level_id' => levelId('upper_secondary'),
        'school_grade_id' => gradeId('grade_9'),
    ])->assertCreated()
        ->assertJsonPath('data.education_level.label', 'Bachillerato')
        ->assertJsonPath('data.school_grade.label', '9°');

    $this->postJson('/api/groups', [
        'name' => 'X',
        'school_year' => 2026,
        'education_level_id' => 999,
        'school_grade_id' => 999,
    ])->assertUnprocessable()->assertJsonValidationErrors(['education_level_id', 'school_grade_id']);
});

it('does not let a level be emptied once chosen', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->putJson("/api/groups/{$group->id}", ['education_level_id' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('education_level_id');
    $this->putJson("/api/v1/groups/{$group->id}/level", ['education_level_id' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('education_level_id');
});

it('lets only direction set the level, unless it hands that power to psychopedagogy', function () {
    $school = School::factory()->create();
    $group = Group::factory()->withoutLevel()->create(['school_id' => $school->id]);
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $teacher);
    $body = ['education_level_id' => levelId('primary'), 'school_grade_id' => gradeId('primary_3')];

    Sanctum::actingAs($teacher);
    $this->putJson("/api/v1/groups/{$group->id}/level", $body)->assertForbidden();

    Sanctum::actingAs($psychopedagogue);
    $this->putJson("/api/v1/groups/{$group->id}/level", $body)->assertForbidden();
    $this->getJson('/api/v1/group-level-setting')->assertOk()
        ->assertJsonPath('data.psychopedagogue_can_set_group_level', false)
        ->assertJsonPath('data.can_set_level', false);
    // Psychopedagogy can't grant it to itself.
    $this->putJson('/api/v1/group-level-setting', ['psychopedagogue_can_set_group_level' => true])->assertForbidden();

    Sanctum::actingAs($director);
    $this->putJson("/api/v1/groups/{$group->id}/level", $body)->assertOk()
        ->assertJsonPath('data.education_level.key', 'primary')
        ->assertJsonPath('data.school_grade.key', 'primary_3');
    $this->putJson('/api/v1/group-level-setting', ['psychopedagogue_can_set_group_level' => true])->assertOk()
        ->assertJsonPath('data.psychopedagogue_can_set_group_level', true);

    Sanctum::actingAs($psychopedagogue->refresh());
    $this->getJson('/api/v1/group-level-setting')->assertOk()->assertJsonPath('data.can_set_level', true);
    $this->putJson("/api/v1/groups/{$group->id}/level", ['education_level_id' => levelId('lower_secondary')])
        ->assertOk()
        ->assertJsonPath('data.education_level.key', 'lower_secondary')
        ->assertJsonPath('data.school_grade', null);
});

it('never lets another school set a group level', function () {
    $group = Group::factory()->create();
    Sanctum::actingAs(User::factory()->director()->create());

    $this->putJson("/api/v1/groups/{$group->id}/level", ['education_level_id' => levelId('primary')])
        ->assertNotFound();
});

it('records who changed a level, when, and from which value to which', function () {
    $school = School::factory()->create();
    $group = Group::factory()->atLevel('primary')->create(['school_id' => $school->id]);
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $this->putJson("/api/v1/groups/{$group->id}/level", ['education_level_id' => levelId('lower_secondary')])->assertOk();

    $log = AuditLog::query()
        ->where('auditable_type', Group::class)
        ->where('auditable_id', $group->id)
        ->where('action', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($log->user_id)->toBe($director->id)
        ->and($log->created_at)->not->toBeNull()
        ->and($log->changes['education_level_id'])->toBe(['before' => levelId('primary'), 'after' => levelId('lower_secondary')]);
});

/**
 * A screening of Primaria with a design in force: 0–40 / 41–70 / 71–100.
 */
function primaryScreening(School $school, array $levels = ['primary']): ScreeningTestType
{
    $type = ScreeningTestType::factory()->create(['school_id' => $school->id, 'levels' => $levels]);
    ScreeningTestDesign::factory()->for($type, 'type')->approved()->create(['bands' => [
        ['name' => 'Descendido', 'color' => 'red', 'max' => 40, 'meaning' => 'Revisar.'],
        ['name' => 'En proceso', 'color' => 'yellow', 'max' => 70, 'meaning' => 'Volver a mirar.'],
        ['name' => 'Adecuado', 'color' => 'green', 'max' => 100, 'meaning' => 'Sin acción.'],
    ]]);

    return $type;
}

function applyWithScore(Group $group, ScreeningTestType $type, float $score): void
{
    $student = Student::factory()->create(['school_id' => $group->school_id]);
    $student->groups()->attach($group, ['school_year' => $group->school_year]);

    test()->postJson("/api/v1/groups/{$group->id}/screening-test-applications", ['screening_test_type_id' => $type->id])
        ->assertCreated();

    $application = ScreeningTestApplication::query()->where('group_id', $group->id)->latest('id')->firstOrFail();
    ScreeningTestResult::query()
        ->where('screening_test_application_id', $application->id)
        ->where('student_id', $student->id)
        ->update(['score' => $score]);
}

function impactStudents(ScreeningTestType $type): array
{
    return test()->postJson("/api/v1/screening-test-types/{$type->id}/impact", ['bands' => [
        ['name' => 'Bajo', 'color' => 'red', 'max' => 50, 'meaning' => 'Revisar.'],
        ['name' => 'Alto', 'color' => 'green', 'max' => null, 'meaning' => 'Sin acción.'],
    ]])->assertOk()->json('data');
}

it('counts in the impact view only groups whose level is in the population, and reports groups without level', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());
    $type = primaryScreening($school);

    applyWithScore(Group::factory()->atLevel('primary')->create(['school_id' => $school->id]), $type, 30);
    applyWithScore(Group::factory()->atLevel('upper_secondary')->create(['school_id' => $school->id]), $type, 90);
    applyWithScore(Group::factory()->withoutLevel()->create(['school_id' => $school->id]), $type, 90);

    $impact = impactStudents($type);

    expect($impact['students'])->toBe(1)
        ->and($impact['groups_without_level'])->toBe(1)
        ->and(collect($impact['current'])->pluck('count')->all())->toBe([1, 0, 0]);
});

it('keeps an applied screening where it was when the group level or the correspondence changes later', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);
    $type = primaryScreening($school);
    $group = Group::factory()->atLevel('primary')->create(['school_id' => $school->id]);
    applyWithScore($group, $type, 30);

    $before = impactStudents($type);
    expect($before['students'])->toBe(1);

    // Direction moves the group to Bachillerato…
    Sanctum::actingAs($director);
    $this->putJson("/api/v1/groups/{$group->id}/level", ['education_level_id' => levelId('upper_secondary')])->assertOk();
    // …and the level ↔ population correspondence of Primaria changes.
    EducationLevel::query()->where('key', 'primary')->update(['screening_population' => 'lower_secondary']);

    Sanctum::actingAs($psychopedagogue);
    expect(impactStudents($type))->toBe($before);

    // A new application from now on takes the new level.
    applyWithScore($group->refresh(), $type, 90);
    expect(impactStudents($type)['students'])->toBe(1);
    expect(ScreeningTestApplication::query()->orderBy('id')->pluck('education_level_id')->all())
        ->toBe([levelId('primary'), levelId('upper_secondary')]);
});

it('does not let a screening without population be applied', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());
    $type = primaryScreening($school, []);
    $group = Group::factory()->atLevel('primary')->create(['school_id' => $school->id]);

    $this->postJson("/api/v1/groups/{$group->id}/screening-test-applications", ['screening_test_type_id' => $type->id])
        ->assertUnprocessable();

    expect(ScreeningTestApplication::query()->count())->toBe(0);
});

it('counts nobody for an application taken while the screening had no population', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());
    $type = primaryScreening($school);
    applyWithScore(Group::factory()->atLevel('primary')->create(['school_id' => $school->id]), $type, 30);
    // As if it had been taken before a population was required.
    ScreeningTestApplication::query()->update(['population_levels' => json_encode([])]);

    expect(impactStudents($type)['students'])->toBe(0);
});

it('recalculates an application with the current level only when a person asks, and records who and when', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    Sanctum::actingAs($psychopedagogue);
    $type = primaryScreening($school);
    $group = Group::factory()->atLevel('upper_secondary')->create(['school_id' => $school->id]);
    applyWithScore($group, $type, 30);
    $application = ScreeningTestApplication::query()->firstOrFail();
    expect(impactStudents($type)['students'])->toBe(0);

    // The group was really Primaria: direction fixes the level…
    Sanctum::actingAs($director);
    $this->putJson("/api/v1/groups/{$group->id}/level", ['education_level_id' => levelId('primary')])->assertOk();
    // …and the applied screening stays where it was until someone recalculates.
    expect(impactStudents($type)['students'])->toBe(0);

    $this->postJson("/api/v1/screening-test-applications/{$application->id}/recalculate")
        ->assertOk()
        ->assertJsonPath('data.counts_in_impact', true)
        ->assertJsonPath('data.recalculated_by_name', $director->name);

    $application->refresh();
    expect($application->education_level_id)->toBe(levelId('primary'))
        ->and($application->recalculated_by_id)->toBe($director->id)
        ->and($application->recalculated_at)->not->toBeNull()
        ->and(impactStudents($type)['students'])->toBe(1);

    // Psychopedagogy may recalculate too.
    Sanctum::actingAs($psychopedagogue);
    $this->postJson("/api/v1/screening-test-applications/{$application->id}/recalculate")
        ->assertOk()
        ->assertJsonPath('data.recalculated_by_name', $psychopedagogue->name);
});

it('does not let a teacher or another school recalculate an application', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());
    $type = primaryScreening($school);
    applyWithScore(Group::factory()->atLevel('primary')->create(['school_id' => $school->id]), $type, 30);
    $application = ScreeningTestApplication::query()->firstOrFail();

    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());
    $this->postJson("/api/v1/screening-test-applications/{$application->id}/recalculate")->assertForbidden();

    Sanctum::actingAs(User::factory()->director()->create());
    $this->postJson("/api/v1/screening-test-applications/{$application->id}/recalculate")->assertNotFound();

    expect($application->refresh()->recalculated_at)->toBeNull();
});
