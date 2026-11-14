<?php

use App\Enums\ClassSessionStatus;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\ClassSession;
use App\Models\CurricularFramework;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\UnitObjective;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Screen 12 — Programa anual (ClickUp 1dfd3mc4bg8): the teacher builds the
 * plan; Psicopedagogía and Dirección read it; unit status and coverage are
 * derived from classes and assessments, never chosen by hand.
 */
function planSetup(): array
{
    $school = School::factory()->create();
    $framework = CurricularFramework::factory()->create();
    $school->curricularFrameworks()->attach($framework, ['active' => true]);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $subject = leadGroup($group, $teacher);

    return [$school, $framework, $teacher, $group, $subject];
}

function makePlan(Group $group, Subject $subject, User $teacher, CurricularFramework $framework): AnnualPlan
{
    return AnnualPlan::factory()->create([
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'teacher_id' => $teacher->id,
        'curricular_framework_id' => $framework->id,
        'year' => 2026,
    ]);
}

function makeObjective(Unit $unit, string $description = 'Objetivo'): UnitObjective
{
    $objective = new UnitObjective;
    $objective->forceFill([
        'school_id' => $unit->annualPlan->school_id,
        'unit_id' => $unit->id,
        'description' => $description,
        'position' => 1,
    ])->save();

    return $objective;
}

it('lets a teacher create the plan of a group-subject they teach', function () {
    [, $framework, $teacher, $group, $subject] = planSetup();
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/annual-plans', [
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'curricular_framework_id' => $framework->id,
        'year' => 2026,
        'description' => 'Que el grupo resuelva problemas de varios pasos.',
    ])->assertCreated()
        ->assertJsonPath('data.year', 2026)
        ->assertJsonPath('data.can_edit', true)
        ->assertJsonPath('data.coverage.units_total', 0);

    $this->assertDatabaseHas('annual_plans', [
        'group_id' => $group->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
    ]);
});

it('rejects a plan for a subject the teacher does not teach in that group', function () {
    [$school, $framework, $teacher, $group] = planSetup();
    $other = Subject::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/annual-plans', [
        'group_id' => $group->id,
        'subject_id' => $other->id,
        'curricular_framework_id' => $framework->id,
        'year' => 2026,
    ])->assertUnprocessable()->assertJsonValidationErrorFor('subject_id');
});

it('rejects a second plan for the same group, subject and year', function () {
    [, $framework, $teacher, $group, $subject] = planSetup();
    makePlan($group, $subject, $teacher, $framework);
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/annual-plans', [
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'curricular_framework_id' => $framework->id,
        'year' => 2026,
    ])->assertUnprocessable()->assertJsonValidationErrorFor('subject_id');
});

it('rejects a framework the school does not have', function () {
    [, , $teacher, $group, $subject] = planSetup();
    $foreign = CurricularFramework::factory()->create();
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/annual-plans', [
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'curricular_framework_id' => $foreign->id,
        'year' => 2026,
    ])->assertUnprocessable()->assertJsonValidationErrorFor('curricular_framework_id');
});

it('does not let Psicopedagogía or Dirección create a plan', function (string $role) {
    [$school, $framework, , $group, $subject] = planSetup();
    Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());

    $this->postJson('/api/v1/annual-plans', [
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'curricular_framework_id' => $framework->id,
        'year' => 2026,
    ])->assertForbidden();
})->with(['director', 'psychopedagogue']);

it('lists only the teacher own plans, and every plan for school-wide roles', function () {
    [$school, $framework, $teacher, $group, $subject] = planSetup();
    makePlan($group, $subject, $teacher, $framework);
    $otherTeacher = User::factory()->forSchool($school)->teacher()->create();
    $otherSubject = leadGroup($group, $otherTeacher);
    makePlan($group, $otherSubject, $otherTeacher, $framework);
    // An individualized (legacy PEI) plan is never part of this screen.
    makePlan($group, $subject, $teacher, $framework)->update([
        'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
    ]);

    Sanctum::actingAs($teacher);
    $this->getJson('/api/v1/annual-plans')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());
    $this->getJson('/api/v1/annual-plans')->assertOk()->assertJsonCount(2, 'data');
});

it('lets school-wide roles read a plan without editing it', function (string $role) {
    [$school, $framework, $teacher, $group, $subject] = planSetup();
    $plan = makePlan($group, $subject, $teacher, $framework);
    Sanctum::actingAs(User::factory()->forSchool($school)->{$role}()->create());

    $this->getJson("/api/v1/annual-plans/{$plan->id}")->assertOk()->assertJsonPath('data.can_edit', false);
    $this->patchJson("/api/v1/annual-plans/{$plan->id}", ['description' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/annual-plans/{$plan->id}/units", [
        'name' => 'Unidad 1', 'start_date' => '2026-03-02', 'end_date' => '2026-04-24',
    ])->assertForbidden();
})->with(['director', 'psychopedagogue']);

it('hides another teacher plan and plans from other schools', function () {
    [$school, $framework, $teacher, $group, $subject] = planSetup();
    $plan = makePlan($group, $subject, $teacher, $framework);

    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());
    $this->getJson("/api/v1/annual-plans/{$plan->id}")->assertForbidden();

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->director()->create());
    $this->getJson("/api/v1/annual-plans/{$plan->id}")->assertNotFound();
});

it('lets the owner add, edit and remove units and objectives', function () {
    [, $framework, $teacher, $group, $subject] = planSetup();
    $plan = makePlan($group, $subject, $teacher, $framework);
    Sanctum::actingAs($teacher);

    $unitId = $this->postJson("/api/v1/annual-plans/{$plan->id}/units", [
        'name' => 'Funciones', 'start_date' => '2026-03-02', 'end_date' => '2026-04-24',
    ])->assertCreated()->assertJsonPath('data.position', 1)->json('data.id');

    $this->patchJson("/api/v1/units/{$unitId}", ['end_date' => '2026-03-01'])
        ->assertUnprocessable()->assertJsonValidationErrorFor('end_date');
    $this->patchJson("/api/v1/units/{$unitId}", ['name' => 'Funciones y su representación'])->assertOk();

    $objectiveId = $this->postJson("/api/v1/units/{$unitId}/objectives", ['description' => 'Representar una función'])
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/unit-objectives/{$objectiveId}", ['description' => 'Representar una función con tabla'])->assertOk();

    $this->getJson("/api/v1/annual-plans/{$plan->id}")
        ->assertJsonPath('data.units.0.name', 'Funciones y su representación')
        ->assertJsonPath('data.units.0.objectives.0.description', 'Representar una función con tabla')
        ->assertJsonPath('data.units.0.status', 'unplanned');

    $this->deleteJson("/api/v1/unit-objectives/{$objectiveId}")->assertNoContent();
    $this->deleteJson("/api/v1/units/{$unitId}")->assertNoContent();
    $this->assertDatabaseCount('units', 0);
});

it('does not let another teacher touch the units of a plan', function () {
    [$school, $framework, $teacher, $group, $subject] = planSetup();
    $plan = makePlan($group, $subject, $teacher, $framework);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);
    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());

    $this->patchJson("/api/v1/units/{$unit->id}", ['name' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/units/{$unit->id}/objectives", ['description' => 'x'])->assertForbidden();
    $this->deleteJson("/api/v1/units/{$unit->id}")->assertForbidden();
});

it('derives unit status and coverage from classes and assessments', function () {
    [$school, $framework, $teacher, $group, $subject] = planSetup();
    $plan = makePlan($group, $subject, $teacher, $framework);
    $u1 = Unit::factory()->create(['annual_plan_id' => $plan->id, 'position' => 1]);
    $u2 = Unit::factory()->create(['annual_plan_id' => $plan->id, 'position' => 2]);
    $u3 = Unit::factory()->create(['annual_plan_id' => $plan->id, 'position' => 3]);

    // Unit 1: both objectives given — one by a class closed «todo», one by an
    // assessment with results.
    $o1 = makeObjective($u1);
    $o2 = makeObjective($u1);
    $class = ClassSession::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'unit_id' => $u1->id,
        'status' => ClassSessionStatus::Delivered, 'date' => '2026-03-10',
    ]);
    $class->unitObjectives()->attach($o1, ['coverage' => 'full']);
    $assessment = Assessment::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'subject_id' => $subject->id,
        'teacher_id' => $teacher->id, 'administered_at' => '2026-04-20',
    ]);
    $assessment->unitObjectives()->attach($o2);
    AssessmentResult::factory()->create([
        'school_id' => $school->id, 'assessment_id' => $assessment->id,
        'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
        'created_by_id' => $teacher->id,
    ]);

    // Unit 2: the objective has a class built but only partly covered → planned.
    $o3 = makeObjective($u2);
    $class2 = ClassSession::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'unit_id' => $u2->id,
        'status' => ClassSessionStatus::Planned, 'date' => '2026-05-10',
    ]);
    $class2->unitObjectives()->attach($o3, ['coverage' => 'partial']);

    // Unit 3: one objective with only a cancelled class, another with nothing.
    $o4 = makeObjective($u3);
    makeObjective($u3);
    $cancelled = ClassSession::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'unit_id' => $u3->id,
        'status' => ClassSessionStatus::Cancelled,
    ]);
    $cancelled->unitObjectives()->attach($o4);

    Sanctum::actingAs($teacher);
    $response = $this->getJson("/api/v1/annual-plans/{$plan->id}")->assertOk();

    $response->assertJsonPath('data.units.0.status', 'given')
        ->assertJsonPath('data.units.1.status', 'planned')
        ->assertJsonPath('data.units.2.status', 'unplanned')
        ->assertJsonPath('data.coverage', [
            'units_total' => 3,
            'units_given' => 1,
            'objectives_total' => 5,
            'objectives_planned' => 3,
            'objectives_given' => 2,
            'objectives_unplanned' => 2,
        ]);

    // Marks: the two non-cancelled classes and the assessment, by date.
    expect(collect($response->json('data.marks'))->pluck('kind')->all())
        ->toBe(['class', 'assessment', 'class']);
});

it('returns 404, not 500, for units and objectives of another school', function () {
    [, $framework, $teacher, $group, $subject] = planSetup();
    $plan = makePlan($group, $subject, $teacher, $framework);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);
    $objective = makeObjective($unit);

    $outsider = User::factory()->forSchool(School::factory()->create())->teacher()->create();
    Sanctum::actingAs($outsider);

    $this->patchJson("/api/v1/units/{$unit->id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/units/{$unit->id}")->assertNotFound();
    $this->postJson("/api/v1/units/{$unit->id}/objectives", ['description' => 'x'])->assertNotFound();
    $this->patchJson("/api/v1/unit-objectives/{$objective->id}", ['description' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/unit-objectives/{$objective->id}")->assertNotFound();

    expect(Unit::query()->whereKey($unit->id)->value('name'))->toBe($unit->name);
    $this->assertDatabaseHas('unit_objectives', ['id' => $objective->id, 'description' => 'Objetivo']);
});

it('does not serve individualized plans on this screen', function () {
    [$school, $framework, $teacher, $group, $subject] = planSetup();
    $plan = makePlan($group, $subject, $teacher, $framework);
    $plan->update(['student_id' => Student::factory()->create(['school_id' => $school->id])->id]);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/annual-plans/{$plan->id}")->assertNotFound();
    $this->patchJson("/api/v1/annual-plans/{$plan->id}", ['description' => 'x'])->assertNotFound();
    $this->postJson("/api/v1/annual-plans/{$plan->id}/units", [
        'name' => 'Unidad', 'start_date' => '2026-03-02', 'end_date' => '2026-04-24',
    ])->assertNotFound();
    $this->patchJson("/api/v1/units/{$unit->id}", ['name' => 'x'])->assertNotFound();
    $this->postJson("/api/v1/units/{$unit->id}/objectives", ['description' => 'x'])->assertNotFound();
});

/**
 * A plan with one unit and one objective that a delivered class recorded
 * «una parte».
 *
 * @return array{0: School, 1: User, 2: AnnualPlan, 3: UnitObjective, 4: ClassSession, 5: Group}
 */
function startedObjectiveSetup(): array
{
    [$school, $framework, $teacher, $group, $subject] = planSetup();
    $plan = makePlan($group, $subject, $teacher, $framework);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);
    $objective = makeObjective($unit);
    $class = ClassSession::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'unit_id' => $unit->id,
        'status' => ClassSessionStatus::Delivered, 'date' => '2026-05-10', 'title' => 'Ecuaciones I',
    ]);
    $class->unitObjectives()->attach($objective, ['coverage' => 'partial']);

    return [$school, $teacher, $plan, $objective, $class, $group];
}

it('shows a partly given objective as started, never as given, with the class to close', function () {
    [$school, $teacher, $plan, $objective, $class, $group] = startedObjectiveSetup();
    // A later class also recorded it in part: that is the one «Lo terminé» opens.
    $later = ClassSession::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'unit_id' => $objective->unit_id,
        'status' => ClassSessionStatus::Delivered, 'date' => '2026-05-17', 'title' => 'Ecuaciones II',
    ]);
    $later->unitObjectives()->attach($objective, ['coverage' => 'partial']);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/annual-plans/{$plan->id}")->assertOk()
        ->assertJsonPath('data.units.0.status', 'planned')
        ->assertJsonPath('data.units.0.given_count', 0)
        ->assertJsonPath('data.units.0.started_count', 1)
        ->assertJsonPath('data.units.0.objectives.0.status', 'started')
        ->assertJsonPath('data.units.0.objectives.0.given', false)
        ->assertJsonPath('data.units.0.objectives.0.class_to_close', [
            'id' => $later->id, 'date' => '2026-05-17', 'title' => 'Ecuaciones II',
        ])
        ->assertJsonPath('data.coverage.objectives_given', 0);
});

it('gives a unit with no objectives no status', function () {
    [, $framework, $teacher, $group, $subject] = planSetup();
    $plan = makePlan($group, $subject, $teacher, $framework);
    Unit::factory()->create(['annual_plan_id' => $plan->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/annual-plans/{$plan->id}")->assertOk()
        ->assertJsonPath('data.units.0.status', null)
        ->assertJsonPath('data.units.0.objectives_count', 0);
});

it('lets the owning teacher turn «una parte» into «todo» on the class record', function () {
    [, $teacher, $plan, $objective, $class] = startedObjectiveSetup();
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/class-sessions/{$class->id}/objectives/{$objective->id}", ['coverage' => 'full'])
        ->assertOk()->assertJsonPath('data.coverage', 'full');

    $this->assertDatabaseHas('class_session_unit_objective', [
        'class_session_id' => $class->id, 'unit_objective_id' => $objective->id, 'coverage' => 'full',
    ]);
    $this->getJson("/api/v1/annual-plans/{$plan->id}")
        ->assertJsonPath('data.units.0.objectives.0.status', 'given')
        ->assertJsonPath('data.units.0.objectives.0.class_to_close', null)
        ->assertJsonPath('data.units.0.status', 'given');
});

it('does not let anyone else correct a class record', function (string $who) {
    [$school, , , $objective, $class] = startedObjectiveSetup();
    $user = match ($who) {
        'other teacher' => User::factory()->forSchool($school)->teacher()->create(),
        'director' => User::factory()->forSchool($school)->director()->create(),
        'psychopedagogue' => User::factory()->forSchool($school)->psychopedagogue()->create(),
    };
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/class-sessions/{$class->id}/objectives/{$objective->id}", ['coverage' => 'full'])
        ->assertForbidden();
    $this->assertDatabaseHas('class_session_unit_objective', ['class_session_id' => $class->id, 'coverage' => 'partial']);
})->with(['other teacher', 'director', 'psychopedagogue']);

it('records «todo» on a picked class that did not work the objective yet, keeping the other closings', function () {
    [$school, $teacher, $plan, $objective, $class, $group] = startedObjectiveSetup();
    // Finished in a later class of the same subject that had no link to it.
    $later = ClassSession::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'subject_id' => $plan->subject_id, 'unit_id' => null,
        'status' => ClassSessionStatus::Delivered, 'date' => '2026-06-02', 'title' => 'Repaso general',
    ]);
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/class-sessions/{$later->id}/objectives/{$objective->id}", ['coverage' => 'full'])
        ->assertOk()
        ->assertJsonPath('data.previous_coverage', null)
        ->assertJsonPath('data.coverage', 'full');

    $this->assertDatabaseHas('class_session_unit_objective', [
        'class_session_id' => $later->id, 'unit_objective_id' => $objective->id, 'coverage' => 'full',
    ]);
    // The first class's «una parte» is kept as it was.
    $this->assertDatabaseHas('class_session_unit_objective', [
        'class_session_id' => $class->id, 'unit_objective_id' => $objective->id, 'coverage' => 'partial',
    ]);
    $this->getJson("/api/v1/annual-plans/{$plan->id}")
        ->assertJsonPath('data.units.0.objectives.0.status', 'given');
});

it('returns 404 for a class of another school', function () {
    [, , , $objective, $class] = startedObjectiveSetup();

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->teacher()->create());
    $this->patchJson("/api/v1/class-sessions/{$class->id}/objectives/{$objective->id}", ['coverage' => 'full'])
        ->assertNotFound();
    $this->getJson("/api/v1/unit-objectives/{$objective->id}/closable-classes")->assertNotFound();
});

it('only records on non-cancelled classes that already happened, with a valid value', function () {
    [, $teacher, , $objective, $class] = startedObjectiveSetup();
    Sanctum::actingAs($teacher);
    $url = "/api/v1/class-sessions/{$class->id}/objectives/{$objective->id}";

    $this->patchJson($url, ['coverage' => 'done'])->assertUnprocessable()->assertJsonValidationErrorFor('coverage');

    $class->update(['date' => now()->addDays(3)->toDateString()]);
    $this->patchJson($url, ['coverage' => 'full'])->assertUnprocessable()->assertJsonValidationErrorFor('coverage');

    $class->update(['date' => '2026-05-10', 'status' => ClassSessionStatus::Cancelled]);
    $this->patchJson($url, ['coverage' => 'full'])->assertUnprocessable()->assertJsonValidationErrorFor('coverage');

    $this->assertDatabaseHas('class_session_unit_objective', ['class_session_id' => $class->id, 'coverage' => 'partial']);
});

it('lists the recent classes of the plan\'s group and subject for «Lo terminé», with what each recorded', function () {
    [$school, $teacher, $plan, $objective, $class, $group] = startedObjectiveSetup();
    $make = fn (array $attrs) => ClassSession::factory()->create(array_merge([
        'school_id' => $school->id, 'group_id' => $group->id, 'subject_id' => $plan->subject_id,
        'unit_id' => null, 'status' => ClassSessionStatus::Delivered,
    ], $attrs));
    $recent = $make(['date' => '2026-06-02', 'title' => 'Sistemas de ecuaciones']);
    $make(['date' => '2026-06-03', 'title' => 'Cancelada', 'status' => ClassSessionStatus::Cancelled]);
    $make(['date' => now()->addDays(5)->toDateString(), 'title' => 'Futura', 'status' => ClassSessionStatus::Planned]);
    $otherSubject = Subject::factory()->create(['school_id' => $school->id]);
    $make(['date' => '2026-06-04', 'title' => 'Otra materia', 'subject_id' => $otherSubject->id]);
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);
    $make(['date' => '2026-06-05', 'title' => 'Otro grupo', 'group_id' => $otherGroup->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/unit-objectives/{$objective->id}/closable-classes")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', ['id' => $recent->id, 'date' => '2026-06-02', 'title' => 'Sistemas de ecuaciones', 'coverage' => null])
        ->assertJsonPath('data.1', ['id' => $class->id, 'date' => '2026-05-10', 'title' => 'Ecuaciones I', 'coverage' => 'partial']);

    // «Buscar otra»: by title (case-insensitive) or by date.
    $this->getJson("/api/v1/unit-objectives/{$objective->id}/closable-classes?search=ecuaciones i")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $class->id);
    $this->getJson("/api/v1/unit-objectives/{$objective->id}/closable-classes?search=2026-06-02")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $recent->id);
    $this->getJson("/api/v1/unit-objectives/{$objective->id}/closable-classes?search=otra materia")->assertOk()
        ->assertJsonCount(0, 'data');
});

it('only lets the plan owner list the classes for «Lo terminé»', function (string $who) {
    [$school, , , $objective] = startedObjectiveSetup();
    $user = match ($who) {
        'other teacher' => User::factory()->forSchool($school)->teacher()->create(),
        'director' => User::factory()->forSchool($school)->director()->create(),
        'psychopedagogue' => User::factory()->forSchool($school)->psychopedagogue()->create(),
    };
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/unit-objectives/{$objective->id}/closable-classes")->assertForbidden();
})->with(['other teacher', 'director', 'psychopedagogue']);

it('never counts a cancelled class closed «todo» as given', function () {
    [, $teacher, $plan, $objective, $class] = startedObjectiveSetup();
    $class->unitObjectives()->updateExistingPivot($objective->id, ['coverage' => 'full']);
    $class->update(['status' => ClassSessionStatus::Cancelled]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/annual-plans/{$plan->id}")->assertOk()
        ->assertJsonPath('data.units.0.objectives.0.given', false)
        ->assertJsonPath('data.units.0.given_count', 0)
        ->assertJsonPath('data.units.0.status', 'unplanned')
        ->assertJsonPath('data.coverage.objectives_given', 0)
        ->assertJsonPath('data.coverage.units_given', 0);
});

it('needs both the class permission and the plan permission to correct a record', function () {
    [$school, $teacher, , $objective, $class, $group] = startedObjectiveSetup();
    $url = "/api/v1/class-sessions/{$class->id}/objectives/{$objective->id}";

    // A co-teacher of the group passes the class check but does not own the plan.
    $coTeacher = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $coTeacher);
    Sanctum::actingAs($coTeacher);
    $this->patchJson($url, ['coverage' => 'full'])->assertForbidden();

    // The plan owner who no longer leads the group passes the plan check only.
    $group->teachers()->detach($teacher);
    Sanctum::actingAs($teacher);
    $this->patchJson($url, ['coverage' => 'full'])->assertForbidden();

    $this->assertDatabaseHas('class_session_unit_objective', ['class_session_id' => $class->id, 'coverage' => 'partial']);
});

it('only records «todo» through «Lo terminé»', function (string $coverage) {
    [, $teacher, , $objective, $class] = startedObjectiveSetup();
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/class-sessions/{$class->id}/objectives/{$objective->id}", ['coverage' => $coverage])
        ->assertUnprocessable()->assertJsonValidationErrorFor('coverage');
    $this->assertDatabaseHas('class_session_unit_objective', ['class_session_id' => $class->id, 'coverage' => 'partial']);
})->with(['partial', 'none']);

it('rejects a class that is not of the plan\'s group or subject', function () {
    [$school, $teacher, $plan, $objective, $class, $group] = startedObjectiveSetup();
    Sanctum::actingAs($teacher);

    // A class of another group of the same teacher, linked by mistake.
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($otherGroup, $teacher, $plan->subject);
    $foreign = ClassSession::factory()->create([
        'school_id' => $school->id, 'group_id' => $otherGroup->id, 'unit_id' => null,
        'status' => ClassSessionStatus::Delivered, 'date' => '2026-05-12',
    ]);
    $foreign->unitObjectives()->attach($objective, ['coverage' => 'partial']);
    $this->patchJson("/api/v1/class-sessions/{$foreign->id}/objectives/{$objective->id}", ['coverage' => 'full'])
        ->assertNotFound();

    // A class of the same group hanging from another subject's unit.
    $otherSubject = Subject::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher, $otherSubject);
    $otherPlan = makePlan($group, $otherSubject, $teacher, $plan->curricularFramework);
    $otherUnit = Unit::factory()->create(['annual_plan_id' => $otherPlan->id]);
    $crossed = ClassSession::factory()->create([
        'school_id' => $school->id, 'group_id' => $group->id, 'unit_id' => $otherUnit->id,
        'status' => ClassSessionStatus::Delivered, 'date' => '2026-05-13',
    ]);
    $crossed->unitObjectives()->attach($objective, ['coverage' => 'partial']);
    $this->patchJson("/api/v1/class-sessions/{$crossed->id}/objectives/{$objective->id}", ['coverage' => 'full'])
        ->assertNotFound();

    expect($foreign->unitObjectives()->first()->pivot->coverage)->toBe('partial')
        ->and($crossed->unitObjectives()->first()->pivot->coverage)->toBe('partial');
});
