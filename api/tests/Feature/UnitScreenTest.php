<?php

use App\Enums\CurricularItemType;
use App\Models\Accommodation;
use App\Models\AnnualPlan;
use App\Models\CurricularCatalog;
use App\Models\CurricularFramework;
use App\Models\CurricularItem;
use App\Models\Group;
use App\Models\School;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\UnitObjective;
use App\Models\User;
use App\Services\SchoolYears\SchoolYearCalendar;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Screen 13 — Unidad (ClickUp 1dfd3mc4bga): the teacher fills the unit
 * planner (no field is mandatory), links objectives to curricular items of
 * the program catalog, and opens «A mano»; Psicopedagogía and Dirección read.
 */
function unitScreenSetup(): array
{
    $school = School::factory()->create();
    $framework = CurricularFramework::factory()->create();
    $school->curricularFrameworks()->attach($framework, ['active' => true]);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $subject = leadGroup($group, $teacher);
    $plan = AnnualPlan::factory()->create([
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'teacher_id' => $teacher->id,
        'curricular_framework_id' => $framework->id,
        'year' => 2026,
    ]);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id, 'name' => 'Ecuaciones', 'position' => 1]);
    $objective = new UnitObjective;
    $objective->forceFill([
        'school_id' => $school->id,
        'unit_id' => $unit->id,
        'description' => 'Traducir un enunciado a lenguaje algebraico',
        'position' => 1,
    ])->save();

    return [$school, $framework, $teacher, $group, $subject, $unit, $objective];
}

it('shows the unit with its objectives and planner to its teacher', function () {
    [, , $teacher, , , $unit, $objective] = unitScreenSetup();
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/units/{$unit->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Ecuaciones')
        ->assertJsonPath('data.can_edit', true)
        ->assertJsonPath('data.objectives.0.id', $objective->id)
        ->assertJsonPath('data.objectives.0.curricular_items', []);

    $keys = collect($response->json('data.planner.fields'))->pluck('key');
    expect($keys)->toContain('contents', 'problem_question', 'differentiation');
});

it('lets psychopedagogy and direction read the unit but not edit it', function () {
    [$school, , , , , $unit] = unitScreenSetup();

    foreach ([User::factory()->forSchool($school)->psychopedagogue()->create(), User::factory()->forSchool($school)->director()->create()] as $reader) {
        Sanctum::actingAs($reader);
        $this->getJson("/api/v1/units/{$unit->id}")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->patchJson("/api/v1/units/{$unit->id}/planner", ['values' => ['contents' => 'x']])->assertForbidden();
    }
});

it('hides another teacher\'s unit and another school\'s unit', function () {
    [$school, , , , , $unit] = unitScreenSetup();

    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());
    $this->getJson("/api/v1/units/{$unit->id}")->assertForbidden();

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->director()->create());
    $this->getJson("/api/v1/units/{$unit->id}")->assertNotFound();
});

it('saves planner values without making any field mandatory', function () {
    [, , $teacher, , , $unit] = unitScreenSetup();
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/units/{$unit->id}/planner", ['values' => [
        'duration_hours' => 12,
        'problem_question' => '¿Cómo sé que mi resultado es correcto?',
        'planned_assessment' => 'Escrito',
    ]])->assertOk();

    expect($unit->refresh()->planner_values)->toMatchArray([
        'duration_hours' => 12,
        'problem_question' => '¿Cómo sé que mi resultado es correcto?',
        'planned_assessment' => 'Escrito',
    ]);

    // Clearing one keeps the others.
    $this->patchJson("/api/v1/units/{$unit->id}/planner", ['values' => ['duration_hours' => null]])
        ->assertOk();
    expect($unit->refresh()->planner_values)->not->toHaveKey('duration_hours')
        ->and($unit->planner_values)->toHaveKey('problem_question');

    $field = collect($this->getJson("/api/v1/units/{$unit->id}")->json('data.planner.fields'))->firstWhere('key', 'planned_assessment');
    expect($field['value'])->toBe('Escrito');
});

it('rejects planner values that do not fit their field', function () {
    [, , $teacher, , , $unit] = unitScreenSetup();
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/units/{$unit->id}/planner", ['values' => [
        'planned_assessment' => 'Cualquier cosa',
        'differentiation' => 'Escrito a mano',
        'key_concept' => 'Sistemas',
        'duration_hours' => 'doce',
    ]])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'values.planned_assessment',
            // Automatic: filled by Aula+.
            'values.differentiation',
            // An IB MYP field is not part of a planner whose program is not MYP.
            'values.key_concept',
            'values.duration_hours',
        ]);
});

it('offers the program catalog and links objectives to its items', function () {
    [, $framework, $teacher, , , $unit, $objective] = unitScreenSetup();
    $catalog = CurricularCatalog::factory()->create(['curricular_framework_id' => $framework->id, 'name' => 'MCN 2022']);
    $item = CurricularItem::factory()->create([
        'curricular_catalog_id' => $catalog->id,
        'type' => CurricularItemType::GeneralCompetency,
        'name' => 'Comunicación',
        'source' => 'Progresiones de Aprendizaje 2022',
        'page' => '14',
    ]);
    $foreign = CurricularItem::factory()->create();
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/units/{$unit->id}/curricular-items")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Comunicación')
        ->assertJsonPath('data.0.source', 'Progresiones de Aprendizaje 2022')
        ->assertJsonPath('data.0.page', '14');

    $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => [$foreign->id]])
        ->assertUnprocessable();

    $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => [$item->id]])
        ->assertOk()
        ->assertJsonPath('data.curricular_items.0.catalog', 'MCN 2022');

    $this->getJson("/api/v1/units/{$unit->id}")
        ->assertJsonPath('data.objectives.0.curricular_items.0.id', $item->id);

    $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => []])
        ->assertOk();
    expect($objective->curricularItems()->count())->toBe(0);
});

it('returns an empty picker when the program has no catalog loaded', function () {
    [, , $teacher, , , $unit] = unitScreenSetup();
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/units/{$unit->id}/curricular-items")->assertOk()->assertJsonCount(0, 'data');
});

it('narrows a linked subject to its subtree plus the general competencies', function () {
    [, $framework, $teacher, , $subject, $unit] = unitScreenSetup();
    $catalog = CurricularCatalog::factory()->create(['curricular_framework_id' => $framework->id]);
    $general = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id, 'type' => CurricularItemType::GeneralCompetency]);
    $math = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id, 'type' => CurricularItemType::Subject, 'name' => 'Matemática']);
    $strand = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id, 'parent_id' => $math->id, 'type' => CurricularItemType::Strand]);
    $history = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id, 'type' => CurricularItemType::Subject, 'name' => 'Historia']);
    $subject->forceFill(['curricular_item_id' => $math->id])->save();
    Sanctum::actingAs($teacher);

    $ids = collect($this->getJson("/api/v1/units/{$unit->id}/curricular-items")->json('data'))->pluck('id');

    expect($ids->all())->toEqualCanonicalizing([$general->id, $math->id, $strand->id])
        ->and($ids)->not->toContain($history->id);
});

it('gives «A mano» the accommodations by type with the students the viewer already sees', function () {
    [$school, , $teacher, $group, $subject, $unit] = unitScreenSetup();
    $ana = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Ana Pérez']);
    $beto = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Beto Ruiz']);
    $group->students()->attach([$ana->id => ['school_year' => 2026], $beto->id => ['school_year' => 2026]]);

    Accommodation::factory()->create(['student_id' => $ana->id, 'type' => 'Tiempo extendido', 'description' => 'Motivo sensible']);
    Accommodation::factory()->create(['student_id' => $beto->id, 'type' => 'Tiempo extendido']);
    // Scoped to a subject the teacher does not teach: not theirs to see.
    $otherSubject = Subject::factory()->create(['school_id' => $school->id]);
    $scoped = Accommodation::factory()->create(['student_id' => $beto->id, 'type' => 'Lectura en voz alta']);
    $scoped->subjects()->attach($otherSubject->id);

    Sanctum::actingAs($teacher);
    $response = $this->getJson("/api/v1/units/{$unit->id}/accommodations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'Tiempo extendido')
        ->assertJsonPath('data.0.student_count', 2)
        ->assertJsonPath('data.0.students.0.full_name', 'Ana Pérez');
    expect($response->getContent())->not->toContain('Motivo sensible');

    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());
    $this->getJson("/api/v1/units/{$unit->id}/accommodations")->assertOk()->assertJsonCount(2, 'data');
});

it('lets only the plan owner write the planner and the objective items', function () {
    [$school, $framework, , , , $unit, $objective] = unitScreenSetup();
    $catalog = CurricularCatalog::factory()->create(['curricular_framework_id' => $framework->id]);
    $item = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id]);

    $readers = [
        User::factory()->forSchool($school)->teacher()->create(),
        User::factory()->forSchool($school)->psychopedagogue()->create(),
        User::factory()->forSchool($school)->director()->create(),
    ];
    foreach ($readers as $user) {
        Sanctum::actingAs($user);
        $this->patchJson("/api/v1/units/{$unit->id}/planner", ['values' => ['contents' => 'x']])->assertForbidden();
        $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => [$item->id]])
            ->assertForbidden();
    }

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->teacher()->create());
    $this->patchJson("/api/v1/units/{$unit->id}/planner", ['values' => ['contents' => 'x']])->assertNotFound();
    $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => []])
        ->assertNotFound();

    expect($unit->refresh()->planner_values)->toBeNull()
        ->and($objective->curricularItems()->count())->toBe(0);
});

it('hides «A mano» from another teacher and another school', function () {
    [$school, , , , , $unit] = unitScreenSetup();

    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());
    $this->getJson("/api/v1/units/{$unit->id}/accommodations")->assertForbidden();
    $this->getJson("/api/v1/units/{$unit->id}/curricular-items")->assertForbidden();

    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->director()->create());
    $this->getJson("/api/v1/units/{$unit->id}/accommodations")->assertNotFound();
    $this->getJson("/api/v1/units/{$unit->id}/curricular-items")->assertNotFound();
});

it('blocks the unit writes in a group of a closed year', function () {
    [$school, , $teacher, $group, , $unit, $objective] = unitScreenSetup();
    SchoolYear::query()->forceCreate([
        'school_id' => $school->id, 'year' => $group->school_year, 'status' => 'closed', 'closed_at' => now(),
    ]);
    app(SchoolYearCalendar::class)->forget();
    Sanctum::actingAs($teacher);

    $this->patchJson("/api/v1/units/{$unit->id}/planner", ['values' => ['contents' => 'x']])
        ->assertForbidden()->assertJsonPath('code', 'school_year_closed');
    $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => []])
        ->assertForbidden()->assertJsonPath('code', 'school_year_closed');
    // Reading still works.
    $this->getJson("/api/v1/units/{$unit->id}")->assertOk();
});

it('does not open a unit of an individualized plan as screen 13', function () {
    [$school, $framework, $teacher, $group, $subject] = unitScreenSetup();
    $student = Student::factory()->create(['school_id' => $school->id]);
    $plan = AnnualPlan::factory()->create([
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'teacher_id' => $teacher->id,
        'curricular_framework_id' => $framework->id,
        'student_id' => $student->id,
        'year' => 2027,
    ]);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/units/{$unit->id}")->assertNotFound();
    $this->getJson("/api/v1/units/{$unit->id}/accommodations")->assertNotFound();
});

it('keeps an item that left the offered catalog saveable and removable', function () {
    [, $framework, $teacher, , , $unit, $objective] = unitScreenSetup();
    $catalog = CurricularCatalog::factory()->create(['curricular_framework_id' => $framework->id]);
    $old = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id]);
    $new = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id]);
    Sanctum::actingAs($teacher);

    $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => [$old->id]])
        ->assertOk();

    // The catalog moves to another program: the old item is no longer offered.
    $catalog->forceFill(['curricular_framework_id' => CurricularFramework::factory()->create()->id])->save();
    $current = CurricularCatalog::factory()->create(['curricular_framework_id' => $framework->id]);
    $new->forceFill(['curricular_catalog_id' => $current->id])->save();

    $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => [$old->id, $new->id]])
        ->assertOk();
    expect($objective->curricularItems()->pluck('curricular_items.id')->all())->toEqualCanonicalizing([$old->id, $new->id]);

    $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => [$new->id]])
        ->assertOk();
    // Once removed, it cannot be added back: it is not offered anymore.
    $this->putJson("/api/v1/unit-objectives/{$objective->id}/curricular-items", ['curricular_item_ids' => [$new->id, $old->id]])
        ->assertUnprocessable();
});

it('names each linked item\'s parent without a query per item', function () {
    [, $framework, $teacher, , , $unit, $objective] = unitScreenSetup();
    $catalog = CurricularCatalog::factory()->create(['curricular_framework_id' => $framework->id]);
    $parent = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id, 'name' => 'Álgebra']);
    $children = CurricularItem::factory()->count(5)->create(['curricular_catalog_id' => $catalog->id, 'parent_id' => $parent->id]);
    $objective->curricularItems()->sync($children->pluck('id')->all());
    Sanctum::actingAs($teacher);

    DB::enableQueryLog();
    $this->getJson("/api/v1/units/{$unit->id}")
        ->assertOk()
        ->assertJsonPath('data.objectives.0.curricular_items.0.parent_name', 'Álgebra');
    $parentLookups = collect(DB::getQueryLog())
        ->filter(fn (array $q) => str_contains($q['query'], 'from "curricular_items"') && ! str_contains($q['query'], 'inner join'))
        ->count();
    expect($parentLookups)->toBeLessThanOrEqual(1);
});
