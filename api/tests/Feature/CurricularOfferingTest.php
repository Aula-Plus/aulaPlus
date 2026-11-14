<?php

use App\Actions\AI\BuildProposalContext;
use App\Enums\AIProposalType;
use App\Enums\PlannerFamily;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\CurricularFramework;
use App\Models\CurricularOffering;
use App\Models\Group;
use App\Models\PlannerFieldDefinition;
use App\Models\School;
use App\Models\SchoolPlannerField;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\CurricularFrameworkSeeder;
use Database\Seeders\OfficialScaleSeeder;
use Laravel\Sanctum\Sanctum;

// ClickUp 1dfd3mc4bgf — screen 23 › «Oferta curricular» and «Planificadores».

/**
 * @return array{school: School, director: User, teacher: User, group: Group, subject: Subject, anep: CurricularFramework, cambridge: CurricularFramework, myp: CurricularFramework}
 */
function curricularScenario(): array
{
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->atLevel('primary')->create(['school_id' => $school->id, 'name' => '5.°A']);
    $subject = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']);
    $group->teachers()->attach($teacher, ['subject_id' => $subject->id]);

    $anep = CurricularFramework::factory()->anep()->create(['name' => 'ANEP EBI', 'education_levels' => ['primary']]);
    $cambridge = CurricularFramework::factory()->family(PlannerFamily::Cambridge)->create(['name' => 'Cambridge Primary', 'education_levels' => ['primary']]);
    $myp = CurricularFramework::factory()->family(PlannerFamily::IbMyp)->create(['name' => 'IB MYP', 'education_levels' => ['primary', 'lower_secondary']]);
    // The school follows the three (Aula+ activates them); ANEP is its mandatory regime.
    $school->curricularFrameworks()->attach([
        $anep->id => ['active' => true, 'mandatory' => true],
        $cambridge->id => ['active' => true, 'mandatory' => false],
        $myp->id => ['active' => true, 'mandatory' => false],
    ]);

    return [
        'school' => $school,
        'director' => $director,
        'teacher' => $teacher,
        'group' => $group,
        'subject' => $subject,
        'anep' => $anep,
        'cambridge' => $cambridge,
        'myp' => $myp,
    ];
}

function offeringUrl(Group $group, Subject $subject, string $suffix = 'curricular-offering'): string
{
    return "/api/v1/groups/{$group->id}/subjects/{$subject->id}/{$suffix}";
}

it('lists one row per group-subject taught, always with the mandatory program of its level, locked', function () {
    $s = curricularScenario();
    // Active for the school but for another level, and not active at all: never offered to 5.°A.
    $bachillerato = CurricularFramework::factory()->anep()->create(['name' => 'ANEP Bachillerato', 'education_levels' => ['upper_secondary']]);
    $s['school']->curricularFrameworks()->attach($bachillerato, ['active' => true, 'mandatory' => true]);
    CurricularFramework::factory()->create(['name' => 'IB DP', 'education_levels' => ['primary']]);
    Sanctum::actingAs($s['director']);

    $response = $this->getJson('/api/v1/curricular-offerings')->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.group.name', '5.°A')
        ->assertJsonPath('data.0.group.level', 'Primaria')
        ->assertJsonPath('data.0.subject.name', 'Matemática')
        ->assertJsonPath('data.0.frameworks', [
            ['id' => $s['anep']->id, 'name' => 'ANEP EBI', 'planner_family' => 'anep', 'locked' => true],
        ]);
    expect(collect($response->json('data.0.available_frameworks'))->pluck('name')->all())
        ->toBe(['ANEP EBI', 'Cambridge Primary', 'IB MYP']);
});

it('lets direction add programs to a group-subject and always keeps the mandatory one', function () {
    $s = curricularScenario();
    Sanctum::actingAs($s['director']);

    // Even if the editor does not send it, ANEP stays.
    $this->putJson(offeringUrl($s['group'], $s['subject']), [
        'curricular_framework_ids' => [$s['cambridge']->id],
    ])->assertOk()
        ->assertJsonPath('data.frameworks.0.name', 'ANEP EBI')
        ->assertJsonPath('data.frameworks.0.locked', true)
        ->assertJsonPath('data.frameworks.1.name', 'Cambridge Primary')
        ->assertJsonPath('data.frameworks.1.locked', false);

    // Replacing the combination removes what is no longer there.
    $this->putJson(offeringUrl($s['group'], $s['subject']), [
        'curricular_framework_ids' => [$s['anep']->id],
    ])->assertOk()->assertJsonCount(1, 'data.frameworks');
    $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => []])
        ->assertOk()->assertJsonPath('data.frameworks.0.name', 'ANEP EBI');

    expect(CurricularOffering::withoutGlobalScopes()->pluck('curricular_framework_id')->all())->toBe([$s['anep']->id]);
    expect(AuditLog::query()->where('auditable_type', (new CurricularOffering)->getMorphClass())->where('action', 'deleted')->count())->toBe(1);
});

it('refuses programs the school does not follow, of another level, or repeated', function () {
    $s = curricularScenario();
    $notActive = CurricularFramework::factory()->create(['name' => 'IGCSE']);
    $otherLevel = CurricularFramework::factory()->create(['name' => 'IB DP', 'education_levels' => ['upper_secondary']]);
    $s['school']->curricularFrameworks()->attach($otherLevel, ['active' => true]);
    Sanctum::actingAs($s['director']);

    $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => [$notActive->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('curricular_framework_ids.0');
    $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => [$otherLevel->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('curricular_framework_ids.0');
    $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => [$s['anep']->id, $s['anep']->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('curricular_framework_ids.0');
    expect(CurricularOffering::withoutGlobalScopes()->count())->toBe(0);
});

it('makes a group without a level still include one of the school\'s mandatory programs', function () {
    $s = curricularScenario();
    $s['group']->update(['education_level_id' => null]);
    Sanctum::actingAs($s['director']);

    $this->getJson('/api/v1/curricular-offerings')->assertOk()->assertJsonPath('data.0.frameworks', []);
    $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => [$s['cambridge']->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('curricular_framework_ids');
    $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => [$s['anep']->id, $s['cambridge']->id]])
        ->assertOk()->assertJsonCount(2, 'data.frameworks');
});

it('has no locked program for a school without a mandatory regime', function () {
    $s = curricularScenario();
    $s['school']->curricularFrameworks()->updateExistingPivot($s['anep']->id, ['mandatory' => false]);
    Sanctum::actingAs($s['director']);

    $this->getJson('/api/v1/curricular-offerings')->assertOk()->assertJsonPath('data.0.frameworks', []);
    $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => [$s['cambridge']->id]])
        ->assertOk()->assertJsonPath('data.frameworks.0.name', 'Cambridge Primary')->assertJsonCount(1, 'data.frameworks');
    $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('curricular_framework_ids');
});

it('keeps the screen to direction: teachers and psychopedagogy cannot read or edit it', function () {
    $s = curricularScenario();
    $psychopedagogue = User::factory()->forSchool($s['school'])->psychopedagogue()->create();

    foreach ([$s['teacher'], $psychopedagogue] as $user) {
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/curricular-offerings')->assertForbidden();
        $this->getJson('/api/v1/planner-settings')->assertForbidden();
        $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => [$s['anep']->id]])
            ->assertForbidden();
        $this->postJson('/api/v1/planner-settings/custom-fields', ['name' => 'Hilo conductor', 'type' => 'short_text'])
            ->assertForbidden();
    }
});

/** A custom planner field of the given school. */
function customPlannerField(School $school): SchoolPlannerField
{
    return Tenancy::forSchool($school, fn () => SchoolPlannerField::query()->create([
        'name' => 'Hilo conductor', 'type' => 'short_text', 'shown' => true, 'position' => 1,
    ]));
}

it('forbids teachers and psychopedagogy to rename, change or remove planner fields', function () {
    $s = curricularScenario();
    $psychopedagogue = User::factory()->forSchool($s['school'])->psychopedagogue()->create();
    $field = customPlannerField($s['school']);
    $notes = PlannerFieldDefinition::query()->where('key', 'teacher_notes')->firstOrFail();

    foreach ([$s['teacher'], $psychopedagogue] as $user) {
        Sanctum::actingAs($user);
        $this->putJson("/api/v1/planner-settings/fields/{$notes->id}", ['school_name' => 'Notas', 'shown' => false])
            ->assertForbidden();
        $this->putJson("/api/v1/planner-settings/custom-fields/{$field->id}", ['name' => 'Otro', 'type' => 'short_text'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/planner-settings/custom-fields/{$field->id}")->assertForbidden();
    }

    expect(SchoolPlannerField::withoutGlobalScopes()->whereKey($field->id)->value('name'))->toBe('Hilo conductor')
        ->and(SchoolPlannerField::withoutGlobalScopes()->whereNotNull('planner_field_definition_id')->count())->toBe(0);
});

it('returns 404 for another school custom planner field', function () {
    $s = curricularScenario();
    $foreign = customPlannerField(School::factory()->create());
    Sanctum::actingAs($s['director']);

    $this->putJson("/api/v1/planner-settings/custom-fields/{$foreign->id}", ['name' => 'Otro', 'type' => 'short_text'])
        ->assertNotFound();
    $this->deleteJson("/api/v1/planner-settings/custom-fields/{$foreign->id}")->assertNotFound();

    expect(SchoolPlannerField::withoutGlobalScopes()->whereKey($foreign->id)->value('name'))->toBe('Hilo conductor');
});

it('never reaches another school group-subject', function () {
    $s = curricularScenario();
    $other = School::factory()->create();
    $otherGroup = Group::factory()->create(['school_id' => $other->id]);
    $otherSubject = Subject::factory()->create(['school_id' => $other->id]);
    Sanctum::actingAs($s['director']);

    $this->putJson(offeringUrl($otherGroup, $otherSubject), ['curricular_framework_ids' => [$s['anep']->id]])
        ->assertNotFound();
    $this->getJson('/api/v1/curricular-offerings')->assertJsonCount(1, 'data');
});

it('shows each framework with its catalog fields and locks the ones the program asks for', function () {
    $s = curricularScenario();
    Sanctum::actingAs($s['director']);

    $data = collect($this->getJson('/api/v1/planner-settings')->assertOk()->json('data'))->keyBy('family');

    expect($data->keys()->all())->toBe(['common', 'anep', 'ib_myp', 'ib_pyp', 'cambridge', 'igcse', 'ib_dp']);
    // IGCSE and IB DP start with only the common fields; the school adds its own.
    expect($data['igcse']['fields'])->toBe([])->and($data['ib_dp']['fields'])->toBe([]);
    expect(collect($data['common']['fields'])->pluck('official_name')->all())->toContain(
        'Duración en horas', 'Contenidos', 'Pregunta problematizadora', 'Diferenciación', 'Notas del docente',
    );
    expect(collect($data['common']['fields'])->every(fn ($f) => $f['required_by_program'] === false && $f['shown']))->toBeTrue();
    expect(collect($data['ib_myp']['fields'])->every(fn ($f) => $f['required_by_program'] === true))->toBeTrue();
    expect(collect($data['ib_pyp']['fields'])->pluck('key')->all())->toBe(['transdisciplinary_theme', 'central_idea', 'lines_of_inquiry']);
});

it('renames and hides common fields, renames but never hides program fields', function () {
    $s = curricularScenario();
    Sanctum::actingAs($s['director']);
    $question = PlannerFieldDefinition::query()->where('key', 'problem_question')->firstOrFail();
    $notes = PlannerFieldDefinition::query()->where('key', 'teacher_notes')->firstOrFail();
    $keyConcept = PlannerFieldDefinition::query()->where('key', 'key_concept')->firstOrFail();

    $this->putJson("/api/v1/planner-settings/fields/{$question->id}", ['school_name' => 'Pregunta guía', 'shown' => true])->assertOk();
    $this->putJson("/api/v1/planner-settings/fields/{$notes->id}", ['school_name' => null, 'shown' => false])->assertOk();
    $this->putJson("/api/v1/planner-settings/fields/{$keyConcept->id}", ['school_name' => 'Concepto', 'shown' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('shown');
    $this->putJson("/api/v1/planner-settings/fields/{$keyConcept->id}", ['school_name' => 'Concepto', 'shown' => true])->assertOk();

    $common = collect($this->getJson('/api/v1/planner-settings')->json('data.0.fields'))->keyBy('key');
    expect($common['problem_question']['school_name'])->toBe('Pregunta guía');
    expect($common['problem_question']['official_name'])->toBe('Pregunta problematizadora');
    expect($common['teacher_notes']['shown'])->toBeFalse();

    // Another school is untouched.
    expect(SchoolPlannerField::withoutGlobalScopes()->where('school_id', '!=', $s['school']->id)->count())->toBe(0);
});

it('adds, edits and removes fields of the school, for every framework or for one', function () {
    $s = curricularScenario();
    Sanctum::actingAs($s['director']);

    $this->postJson('/api/v1/planner-settings/custom-fields', [
        'name' => 'Hilo conductor', 'type' => 'short_text', 'purpose' => 'ai',
        'description' => 'La idea que atraviesa todas las clases de la unidad.',
    ])->assertCreated();
    $this->postJson('/api/v1/planner-settings/custom-fields', [
        'name' => 'Salida didáctica', 'type' => 'list', 'family' => 'ib_myp', 'options' => ['Sí', 'No'], 'required_by_school' => true,
        'purpose' => 'record',
    ])->assertCreated();
    // An own field for IB DP, which brings no fields of its own.
    $this->postJson('/api/v1/planner-settings/custom-fields', [
        'name' => 'Theory of Knowledge', 'type' => 'long_text', 'family' => 'ib_dp', 'purpose' => 'ai',
    ])->assertCreated();
    // It must say whether it is for the AI or only for the record.
    $this->postJson('/api/v1/planner-settings/custom-fields', ['name' => 'X', 'type' => 'short_text'])
        ->assertUnprocessable()->assertJsonValidationErrors('purpose');
    $this->postJson('/api/v1/planner-settings/custom-fields', ['name' => 'X', 'type' => 'short_text', 'purpose' => 'both'])
        ->assertUnprocessable()->assertJsonValidationErrors('purpose');
    // List types need options; text types take none; Aula+'s automatic fields are not for schools.
    $this->postJson('/api/v1/planner-settings/custom-fields', ['name' => 'X', 'type' => 'list', 'purpose' => 'ai'])
        ->assertUnprocessable()->assertJsonValidationErrors('options');
    $this->postJson('/api/v1/planner-settings/custom-fields', ['name' => 'X', 'type' => 'automatic'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->postJson('/api/v1/planner-settings/custom-fields', ['name' => 'X', 'type' => 'short_text', 'family' => 'common'])
        ->assertUnprocessable()->assertJsonValidationErrors('family');

    $data = collect($this->getJson('/api/v1/planner-settings')->json('data'))->keyBy('family');
    expect($data['common']['custom_fields'][0]['school_name'])->toBe('Hilo conductor');
    expect($data['common']['custom_fields'][0]['description'])->toBe('La idea que atraviesa todas las clases de la unidad.');
    expect($data['common']['custom_fields'][0]['purpose'])->toBe('ai');
    expect($data['ib_myp']['custom_fields'][0]['required_by_school'])->toBeTrue();
    expect($data['ib_myp']['custom_fields'][0]['purpose'])->toBe('record');
    expect($data['ib_dp']['custom_fields'][0]['school_name'])->toBe('Theory of Knowledge');

    $id = $data['common']['custom_fields'][0]['id'];
    $this->putJson("/api/v1/planner-settings/custom-fields/{$id}", ['name' => 'Eje del año', 'type' => 'long_text', 'purpose' => 'record'])->assertOk()
        ->assertJsonPath('data.0.custom_fields.0.school_name', 'Eje del año');
    $this->deleteJson("/api/v1/planner-settings/custom-fields/{$id}")->assertOk()
        ->assertJsonPath('data.0.custom_fields', []);
});

it('resolves the unit planner of a group-subject: common fields plus those of its frameworks', function () {
    $s = curricularScenario();
    Sanctum::actingAs($s['director']);
    $this->putJson(offeringUrl($s['group'], $s['subject']), ['curricular_framework_ids' => [$s['myp']->id]])->assertOk();
    $notes = PlannerFieldDefinition::query()->where('key', 'teacher_notes')->firstOrFail();
    $this->putJson("/api/v1/planner-settings/fields/{$notes->id}", ['school_name' => null, 'shown' => false])->assertOk();
    $this->postJson('/api/v1/planner-settings/custom-fields', ['name' => 'Hilo conductor', 'type' => 'short_text', 'purpose' => 'ai'])->assertCreated();

    // The teacher of Matemática in 5.°A sees it, like screen 13 will.
    Sanctum::actingAs($s['teacher']);
    $response = $this->getJson(offeringUrl($s['group'], $s['subject'], 'planner-fields'))->assertOk();

    $keys = collect($response->json('data.fields'))->pluck('key');
    expect($response->json('data.frameworks.*.name'))->toBe(['ANEP EBI', 'IB MYP']);
    expect($keys)->toContain('contents', 'mcn_priority_competencies', 'key_concept', 'reflection_after')
        ->not->toContain('teacher_notes', 'strand', 'central_idea');
    expect($keys->search('contents'))->toBeLessThan($keys->search('mcn_priority_competencies'));
    expect(collect($response->json('data.fields'))->firstWhere('school_name', 'Hilo conductor'))->not->toBeNull();
});

it('only lets the teacher of that subject in that group read its planner', function () {
    $s = curricularScenario();
    $otherSubject = Subject::factory()->create(['school_id' => $s['school']->id]);
    $otherTeacher = User::factory()->forSchool($s['school'])->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($s['school'])->psychopedagogue()->create();

    Sanctum::actingAs($s['teacher']);
    $this->getJson(offeringUrl($s['group'], $otherSubject, 'planner-fields'))->assertForbidden();
    Sanctum::actingAs($otherTeacher);
    $this->getJson(offeringUrl($s['group'], $s['subject'], 'planner-fields'))->assertForbidden();
    Sanctum::actingAs($psychopedagogue);
    $this->getJson(offeringUrl($s['group'], $s['subject'], 'planner-fields'))->assertOk();
});

it('sends the AI the programs of the group-subject when generating a class from its unit', function () {
    $s = curricularScenario();
    // The group as a whole follows Cambridge (another subject), but Matemática follows ANEP.
    $s['group']->curricularFrameworks()->attach($s['cambridge']);
    Tenancy::forSchool($s['school'], fn () => CurricularOffering::create([
        'group_id' => $s['group']->id, 'subject_id' => $s['subject']->id, 'curricular_framework_id' => $s['anep']->id,
    ]));
    $plan = AnnualPlan::factory()->create([
        'school_id' => $s['school']->id, 'group_id' => $s['group']->id, 'subject_id' => $s['subject']->id,
        'teacher_id' => $s['teacher']->id, 'curricular_framework_id' => $s['anep']->id,
    ]);
    $unit = Unit::factory()->create(['annual_plan_id' => $plan->id]);
    $proposal = AIProposal::factory()->create([
        'school_id' => $s['school']->id,
        'group_id' => $s['group']->id,
        'requested_by_id' => $s['teacher']->id,
        'type' => AIProposalType::ClassSession,
        'input_parameters' => ['unit_id' => $unit->id, 'focus' => 'fracciones'],
    ]);

    $context = Tenancy::forSchool($s['school'], fn () => (new BuildProposalContext)($proposal));

    expect($context['curricular_framework']['frameworks'])->toBe(['ANEP EBI']);
});

it('backfills the planner family of the known frameworks', function () {
    $this->seed(CurricularFrameworkSeeder::class);

    expect(CurricularFramework::query()->where('name', 'ANEP Bachillerato')->value('planner_family'))->toBe(PlannerFamily::Anep);
    expect(CurricularFramework::query()->where('name', 'IB DP')->value('planner_family'))->toBe(PlannerFamily::IbDp);
    expect(CurricularFramework::query()->where('name', 'IGCSE')->value('planner_family'))->toBe(PlannerFamily::Igcse);
    expect(CurricularFramework::query()->where('name', 'ANEP EBI')->first()->education_levels)
        ->toBe(['initial', 'primary', 'lower_secondary']);
});

it('records results with the scale of the row\'s programs, not the group\'s frameworks', function () {
    $this->seed(OfficialScaleSeeder::class);
    $school = School::factory()->create();
    $ebi = CurricularFramework::query()->where('name', 'ANEP EBI')->firstOrFail();
    $cambridge = CurricularFramework::query()->where('name', 'Cambridge Primary')->firstOrFail();
    $school->curricularFrameworks()->attach([
        $ebi->id => ['active' => true, 'mandatory' => true],
        $cambridge->id => ['active' => true, 'mandatory' => false],
    ]);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->atLevel('primary')->create(['school_id' => $school->id]);
    // The group as a whole is linked to Cambridge only (no official scale).
    $group->curricularFrameworks()->attach($cambridge);
    $subject = leadGroup($group, $teacher);
    $assessment = Assessment::factory()->create([
        'group_id' => $group->id, 'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
    ]);
    Sanctum::actingAs($teacher);

    // The row 5.°A · subject carries ANEP EBI (mandatory), so its Primaria scale applies.
    $this->getJson("/api/v1/assessments/{$assessment->id}/result-scale")->assertOk()
        ->assertJsonPath('data.mode', 'scale')
        ->assertJsonPath('data.scale.framework.name', 'ANEP EBI');
});
