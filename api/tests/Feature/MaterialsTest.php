<?php

use App\Models\Assessment;
use App\Models\CurricularCatalog;
use App\Models\CurricularFramework;
use App\Models\CurricularItem;
use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\Material;
use App\Models\School;
use App\Models\SchoolGrade;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\MaterialPublishedNotification;
use App\Notifications\MaterialRemovedNotification;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// Screen 19 «Materiales» (ClickUp 1dfd3mc4bgx): «Lo generado» and the school's
// catalog behind the subject × grade prefilter; private uploads; publishing
// takes effect on save, Psicopedagogía and Dirección are told and can take it
// out; nothing of a student reaches the catalog.

beforeEach(function () {
    Storage::fake('materials');
    Storage::disk('materials')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration) => 'https://files.test/'.$path.'?expires='.$expiration->getTimestamp()
    );

    $this->school = School::factory()->create();
    $this->teacher = User::factory()->forSchool($this->school)->teacher()->create(['name' => 'Ana Docente']);
    $this->otherTeacher = User::factory()->forSchool($this->school)->teacher()->create();
    $this->psycho = User::factory()->forSchool($this->school)->psychopedagogue()->create();
    $this->director = User::factory()->forSchool($this->school)->director()->create();

    $this->grade = SchoolGrade::query()->where('key', 'primary_5')->firstOrFail();
    $this->group = Group::factory()->create([
        'school_id' => $this->school->id,
        'name' => '5°A',
        'education_level_id' => EducationLevel::query()->where('key', 'primary')->value('id'),
        'school_grade_id' => $this->grade->id,
    ]);
    $this->subject = Subject::factory()->create(['school_id' => $this->school->id, 'name' => 'Matemática']);
    leadGroup($this->group, $this->teacher, $this->subject);
    $this->otherGroup = Group::factory()->create(['school_id' => $this->school->id, 'school_grade_id' => $this->grade->id]);
    leadGroup($this->otherGroup, $this->otherTeacher, $this->subject);

    $this->upload = fn (array $extra = []) => $this->post('/api/v1/materials', [
        'file' => UploadedFile::fake()->create('guia.pdf', 20, 'application/pdf'),
        'group_id' => $this->group->id,
        'subject_id' => $this->subject->id,
        'title' => 'Fracciones equivalentes, guía',
        'type' => 'guide',
        'tags' => ['fracciones'],
        ...$extra,
    ], ['Accept' => 'application/json']);

    $this->list = fn (string $tab) => $this->getJson("/api/v1/materials?tab={$tab}&subject_id={$this->subject->id}&grade_id={$this->grade->id}");
});

it('offers the teacher only the subjects, grades and groups they teach', function () {
    $foreignSubject = Subject::factory()->create(['school_id' => $this->school->id]);
    leadGroup($this->otherGroup, $this->otherTeacher, $foreignSubject);
    Sanctum::actingAs($this->teacher);

    $this->getJson('/api/v1/materials/options')
        ->assertOk()
        ->assertJsonPath('data.subjects', [['id' => $this->subject->id, 'name' => 'Matemática']])
        ->assertJsonPath('data.grades.0.label', $this->grade->label)
        ->assertJsonCount(1, 'data.groups')
        ->assertJsonPath('data.groups.0.subject_ids', [$this->subject->id]);
});

it('gives Dirección the whole school to read and nowhere to upload', function () {
    Sanctum::actingAs($this->director);

    $this->getJson('/api/v1/materials/options')->assertOk()->assertJsonCount(0, 'data.groups');
    ($this->upload)()->assertForbidden();
});

it('stores an upload privately with its metadata, only for a group and subject the teacher teaches', function () {
    Sanctum::actingAs($this->teacher);

    $response = ($this->upload)()->assertCreated()
        ->assertJsonPath('data.title', 'Fracciones equivalentes, guía')
        ->assertJsonPath('data.catalog_status', null)
        ->assertJsonPath('data.can.publish', true);

    $material = Material::query()->findOrFail($response->json('data.id'));
    expect($material->school_grade_id)->toBe($this->grade->id)
        ->and($material->owner_id)->toBe($this->teacher->id);
    Storage::disk('materials')->assertExists($material->path);
    expect($response->json('data'))->not->toHaveKey('path');

    ($this->upload)(['group_id' => $this->otherGroup->id])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
});

it('rejects program elements outside the subject catalog and accepts its own', function () {
    $framework = CurricularFramework::factory()->create();
    $this->school->curricularFrameworks()->attach($framework, ['active' => true, 'mandatory' => true]);
    $catalog = CurricularCatalog::factory()->create(['curricular_framework_id' => $framework->id]);
    $item = CurricularItem::factory()->create(['curricular_catalog_id' => $catalog->id]);
    $foreign = CurricularItem::factory()->create();
    Sanctum::actingAs($this->teacher);

    $this->getJson("/api/v1/materials/curricular-items?group_id={$this->group->id}&subject_id={$this->subject->id}")
        ->assertOk()->assertJsonPath('data.0.id', $item->id);
    $this->getJson("/api/v1/materials/curricular-items?group_id={$this->otherGroup->id}&subject_id={$this->subject->id}")
        ->assertForbidden();

    ($this->upload)(['curricular_item_ids' => [$foreign->id]])->assertUnprocessable()->assertJsonValidationErrors('curricular_item_ids');
    ($this->upload)(['curricular_item_ids' => [$item->id]])->assertCreated()->assertJsonPath('data.curricular_items.0.id', $item->id);
});

it('needs «Revisé que no tenga datos de alumnos» to publish a file, and records who and when', function () {
    Notification::fake();
    Sanctum::actingAs($this->teacher);

    ($this->upload)(['publish' => true])->assertUnprocessable()->assertJsonValidationErrors('confirm_no_student_data');

    $id = ($this->upload)(['publish' => true, 'confirm_no_student_data' => true])
        ->assertCreated()
        ->assertJsonPath('data.catalog_status', 'published')
        ->json('data.id');

    $material = Material::query()->findOrFail($id);
    expect($material->no_student_data_confirmed_by_id)->toBe($this->teacher->id)
        ->and($material->no_student_data_confirmed_at)->not->toBeNull()
        ->and($material->published_by_id)->toBe($this->teacher->id);

    Notification::assertSentTo([$this->psycho, $this->director], MaterialPublishedNotification::class);
    Notification::assertNotSentTo([$this->teacher, $this->otherTeacher], MaterialPublishedNotification::class);
});

it('keeps a material «solo tuyo» until published, and lets the whole school open the catalog', function () {
    Sanctum::actingAs($this->teacher);
    $id = ($this->upload)()->json('data.id');

    Sanctum::actingAs($this->otherTeacher);
    $this->getJson("/api/v1/materials/{$id}/download")->assertForbidden();
    ($this->list)('catalog')->assertOk()->assertJsonCount(0, 'data');

    Sanctum::actingAs($this->psycho);
    ($this->list)('generated')->assertOk()->assertJsonPath('data.0.id', $id);
    $this->getJson("/api/v1/materials/{$id}/download")->assertOk();

    Sanctum::actingAs($this->otherTeacher);
    $this->postJson("/api/v1/materials/{$id}/publish", ['confirm_no_student_data' => true])->assertForbidden();

    Sanctum::actingAs($this->teacher);
    $this->postJson("/api/v1/materials/{$id}/publish", ['confirm_no_student_data' => true])->assertOk();

    Sanctum::actingAs($this->otherTeacher);
    ($this->list)('catalog')->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.group', null)
        ->assertJsonPath('data.0.published_by_name', 'Ana Docente');
    $this->getJson("/api/v1/materials/{$id}/download")->assertOk()
        ->assertJsonPath('data.expires_in_minutes', 5);
    ($this->list)('generated')->assertOk()->assertJsonCount(0, 'data');
});

it('applies the hard prefilter: no results without subject and grade', function () {
    Sanctum::actingAs($this->teacher);

    $this->getJson('/api/v1/materials?tab=generated')->assertUnprocessable()->assertJsonValidationErrors(['subject_id', 'grade_id']);
});

it('lists the teacher\'s confirmed assessments in «Lo generado»', function () {
    Assessment::factory()->create(['group_id' => $this->group->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id, 'purpose' => 'Escrito de fracciones']);
    Assessment::factory()->create(['group_id' => $this->otherGroup->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->otherTeacher->id]);
    Sanctum::actingAs($this->teacher);

    ($this->list)('generated')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'assessment')
        ->assertJsonPath('data.0.title', 'Escrito de fracciones')
        ->assertJsonPath('data.0.can.publish', true);
});

it('publishes an assessment to the catalog without any student mark', function () {
    Notification::fake();
    $student = Student::factory()->create(['school_id' => $this->school->id, 'full_name' => 'Lucía Pereyra']);
    $this->group->students()->attach($student->id, ['school_year' => now()->year]);
    $assessment = Assessment::factory()->create([
        'group_id' => $this->group->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id,
        'purpose' => 'Escrito de fracciones de 5°A',
        'content' => [
            'instructions' => 'Lucía Pereyra puede usar la tabla. Resolvé los ejercicios.',
            'items' => [['prompt' => 'Sumá 1/2 + 1/4'], ['prompt' => 'Explicale a Lucia cómo lo pensaste']],
            'accommodations_applied' => [['student' => 'Lucía Pereyra', 'adjustment' => 'Tiempo extendido']],
        ],
    ]);

    Sanctum::actingAs($this->otherTeacher);
    $this->postJson("/api/v1/assessments/{$assessment->id}/material")->assertForbidden();

    Sanctum::actingAs($this->teacher);
    $id = $this->postJson("/api/v1/assessments/{$assessment->id}/material")->assertOk()
        ->assertJsonPath('data.catalog_status', 'published')
        ->json('data.id');

    $material = Material::query()->findOrFail($id);
    $json = json_encode($material->content, JSON_UNESCAPED_UNICODE).' '.$material->title;
    expect($json)->not->toContain('Lucía')
        ->and($json)->not->toContain('Lucia')
        ->and($json)->not->toContain('Pereyra')
        ->and($json)->not->toContain('5°A')
        ->and($json)->not->toContain('Tiempo extendido')
        ->and($material->content['items'][0]['prompt'])->toBe('Sumá 1/2 + 1/4');

    Sanctum::actingAs($this->otherTeacher);
    $this->getJson("/api/v1/materials/{$id}")->assertOk()->assertJsonPath('data.group', null);
    Notification::assertSentTo([$this->psycho, $this->director], MaterialPublishedNotification::class);
});

it('lets Psicopedagogía and Dirección take a material out of the catalog, and nobody else', function () {
    Sanctum::actingAs($this->teacher);
    $id = ($this->upload)(['publish' => true, 'confirm_no_student_data' => true])->json('data.id');

    Sanctum::actingAs($this->otherTeacher);
    $this->deleteJson("/api/v1/materials/{$id}/catalog")->assertForbidden();

    Sanctum::actingAs($this->director);
    ($this->list)('catalog')->assertJsonPath('data.0.can.remove_from_catalog', true);
    $this->deleteJson("/api/v1/materials/{$id}/catalog")->assertOk()->assertJsonPath('data.catalog_status', 'removed');

    expect(Material::query()->findOrFail($id)->removed_by_id)->toBe($this->director->id);
    Sanctum::actingAs($this->otherTeacher);
    ($this->list)('catalog')->assertJsonCount(0, 'data');
});

it('lets only the owner delete an upload, and removes the file', function () {
    Sanctum::actingAs($this->teacher);
    $id = ($this->upload)()->json('data.id');
    $path = Material::query()->findOrFail($id)->path;

    Sanctum::actingAs($this->psycho);
    $this->deleteJson("/api/v1/materials/{$id}")->assertForbidden();

    Sanctum::actingAs($this->teacher);
    $this->deleteJson("/api/v1/materials/{$id}")->assertNoContent();
    Storage::disk('materials')->assertMissing($path);
});

it('never shows another school\'s materials', function () {
    Sanctum::actingAs($this->teacher);
    $id = ($this->upload)(['publish' => true, 'confirm_no_student_data' => true])->json('data.id');

    $otherSchool = School::factory()->create();
    $stranger = User::factory()->forSchool($otherSchool)->director()->create();
    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/materials/{$id}")->assertNotFound();
    $this->getJson("/api/v1/materials?tab=catalog&subject_id={$this->subject->id}&grade_id={$this->grade->id}")
        ->assertOk()->assertJsonCount(0, 'data');
    expect(Tenancy::forSchool($otherSchool, fn () => Material::query()->count()))->toBe(0);
});

it('takes an assessment\'s catalog copy out with it when the assessment is deleted', function () {
    Notification::fake();
    $assessment = Assessment::factory()->create(['group_id' => $this->group->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id]);
    Sanctum::actingAs($this->teacher);
    $id = $this->postJson("/api/v1/assessments/{$assessment->id}/material")->assertOk()->json('data.id');

    $this->deleteJson("/api/v1/assessments/{$assessment->id}")->assertNoContent();

    expect(Material::query()->find($id))->toBeNull();
    Sanctum::actingAs($this->otherTeacher);
    ($this->list)('catalog')->assertOk()->assertJsonCount(0, 'data');
});

it('pages «Lo generado» across uploads and assessments, and the catalog', function () {
    Notification::fake();
    Sanctum::actingAs($this->teacher);
    foreach (range(1, 30) as $i) {
        Assessment::factory()->create([
            'group_id' => $this->group->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id,
            'created_at' => now()->subDays(60 + $i),
        ]);
    }
    foreach (range(1, 25) as $i) {
        $this->travelTo(now()->subDays($i));
        ($this->upload)(['publish' => true, 'confirm_no_student_data' => true])->assertCreated();
        $this->travelBack();
    }

    $first = ($this->list)('generated')->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('meta.total', 55)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('data.0.kind', 'upload')
        ->assertJsonPath('data.49.kind', 'assessment');
    $second = $this->getJson("/api/v1/materials?tab=generated&subject_id={$this->subject->id}&grade_id={$this->grade->id}&page=2")
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.current_page', 2);
    $keys = fn ($response) => collect($response->json('data'))->map(fn ($row) => $row['kind'].$row['id']);
    expect($keys($first)->intersect($keys($second)))->toBeEmpty();

    Sanctum::actingAs($this->otherTeacher);
    ($this->list)('catalog')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.last_page', 1);
});

it('keeps an unpublished or removed material away from other teachers', function () {
    Sanctum::actingAs($this->teacher);
    $id = ($this->upload)()->json('data.id');

    Sanctum::actingAs($this->otherTeacher);
    $this->getJson("/api/v1/materials/{$id}")->assertForbidden();
    $this->getJson("/api/v1/materials/{$id}/download")->assertForbidden();

    Sanctum::actingAs($this->teacher);
    $this->postJson("/api/v1/materials/{$id}/publish", ['confirm_no_student_data' => true])->assertOk();
    Sanctum::actingAs($this->otherTeacher);
    $this->getJson("/api/v1/materials/{$id}")->assertOk();

    Sanctum::actingAs($this->psycho);
    $this->deleteJson("/api/v1/materials/{$id}/catalog")->assertOk();
    Sanctum::actingAs($this->otherTeacher);
    $this->getJson("/api/v1/materials/{$id}")->assertForbidden();
    $this->getJson("/api/v1/materials/{$id}/download")->assertForbidden();
});

it('validates where an upload goes: another school\'s group, Dirección and Psicopedagogía', function () {
    $otherSchool = School::factory()->create();
    $foreignGroup = Tenancy::forSchool($otherSchool, fn () => Group::factory()->create(['school_id' => $otherSchool->id]));

    Sanctum::actingAs($this->teacher);
    ($this->upload)(['group_id' => $foreignGroup->id])->assertUnprocessable();

    Sanctum::actingAs($this->director);
    $this->getJson("/api/v1/materials/curricular-items?group_id={$this->group->id}&subject_id={$this->subject->id}")->assertForbidden();

    // Psicopedagogía uploads to any group × subject of the school.
    Sanctum::actingAs($this->psycho);
    ($this->upload)(['group_id' => $this->otherGroup->id])->assertCreated();
});

it('publishes an assessment copy only from its assessment, and lets Dirección take it out from «Lo generado»', function () {
    Notification::fake();
    $assessment = Assessment::factory()->create(['group_id' => $this->group->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id]);
    Sanctum::actingAs($this->teacher);
    $id = $this->postJson("/api/v1/assessments/{$assessment->id}/material")->assertOk()->json('data.id');
    $this->postJson("/api/v1/materials/{$id}/publish", ['confirm_no_student_data' => true])->assertUnprocessable();

    Sanctum::actingAs($this->director);
    ($this->list)('generated')->assertOk()
        ->assertJsonPath('data.0.kind', 'assessment')
        ->assertJsonPath('data.0.can.remove_from_catalog', true);
    $this->deleteJson("/api/v1/materials/{$id}/catalog")->assertOk()->assertJsonPath('data.catalog_status', 'removed');
});

it('tells the owner their material was taken out of the catalog, with the reason when written', function () {
    Sanctum::actingAs($this->teacher);
    $id = ($this->upload)(['publish' => true, 'confirm_no_student_data' => true])->json('data.id');

    Sanctum::actingAs($this->director);
    $this->deleteJson("/api/v1/materials/{$id}/catalog", ['reason' => '  Ya hay una guía igual en la carpeta.  '])
        ->assertOk()->assertJsonPath('data.catalog_status', 'removed');

    $material = Material::query()->findOrFail($id);
    expect($material->removed_reason)->toBe('Ya hay una guía igual en la carpeta.')
        ->and($material->removed_by_id)->toBe($this->director->id);

    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.data.type', 'material_removed')
        ->assertJsonPath('data.0.data.material_id', $id)
        ->assertJsonPath('data.0.data.material_title', 'Fracciones equivalentes, guía')
        ->assertJsonPath('data.0.data.reason', 'Ya hay una guía igual en la carpeta.');
    $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.count', 1);

    // Nobody else hears about it.
    Sanctum::actingAs($this->otherTeacher);
    $this->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
});

it('tells the owner without a reason when none is written, and never the owner who takes it out', function () {
    Notification::fake();
    Sanctum::actingAs($this->teacher);
    $id = ($this->upload)(['publish' => true, 'confirm_no_student_data' => true])->json('data.id');

    Sanctum::actingAs($this->psycho);
    $this->deleteJson("/api/v1/materials/{$id}/catalog", ['reason' => '   '])->assertOk();

    expect(Material::query()->findOrFail($id)->removed_reason)->toBeNull();
    Notification::assertSentTo($this->teacher, MaterialRemovedNotification::class,
        fn (MaterialRemovedNotification $notice) => $notice->toArray($this->teacher)['reason'] === null
            && $notice->toArray($this->teacher)['material_id'] === $id);
    Notification::assertNotSentTo([$this->psycho, $this->director, $this->otherTeacher], MaterialRemovedNotification::class);

    // Psicopedagogía taking its own upload out tells nobody.
    $own = ($this->upload)(['group_id' => $this->otherGroup->id, 'publish' => true, 'confirm_no_student_data' => true])->json('data.id');
    $this->deleteJson("/api/v1/materials/{$own}/catalog", ['reason' => 'Lo reviso'])->assertOk();
    Notification::assertSentToTimes($this->teacher, MaterialRemovedNotification::class, 1);
    Notification::assertNotSentTo($this->psycho, MaterialRemovedNotification::class);
});

it('guards «Sacar del catálogo»: teacher non-owner 403, another school 404, too long a reason 422', function () {
    Notification::fake();
    Sanctum::actingAs($this->teacher);
    $id = ($this->upload)(['publish' => true, 'confirm_no_student_data' => true])->json('data.id');

    Sanctum::actingAs($this->otherTeacher);
    $this->deleteJson("/api/v1/materials/{$id}/catalog", ['reason' => 'x'])->assertForbidden();
    // Not even the owner, being a teacher.
    Sanctum::actingAs($this->teacher);
    $this->deleteJson("/api/v1/materials/{$id}/catalog")->assertForbidden();

    $stranger = User::factory()->forSchool(School::factory()->create())->director()->create();
    Sanctum::actingAs($stranger);
    $this->deleteJson("/api/v1/materials/{$id}/catalog")->assertNotFound();

    Sanctum::actingAs($this->director);
    $this->deleteJson("/api/v1/materials/{$id}/catalog", ['reason' => str_repeat('a', 501)])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->deleteJson("/api/v1/materials/{$id}/catalog", ['reason' => ['not', 'text']])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    expect(Material::query()->findOrFail($id)->isPublished())->toBeTrue();
    Notification::assertNothingSentTo($this->teacher);
});

it('sends Psicopedagogía and Dirección the usual notice when the owner publishes it again, and clears the removal', function () {
    Notification::fake();
    Sanctum::actingAs($this->teacher);
    $id = ($this->upload)(['publish' => true, 'confirm_no_student_data' => true])->json('data.id');

    Sanctum::actingAs($this->director);
    $this->deleteJson("/api/v1/materials/{$id}/catalog", ['reason' => 'Le falta la consigna'])->assertOk();

    Sanctum::actingAs($this->teacher);
    ($this->list)('generated')->assertJsonPath('data.0.can.publish', true);
    $this->postJson("/api/v1/materials/{$id}/publish", ['confirm_no_student_data' => true])
        ->assertOk()->assertJsonPath('data.catalog_status', 'published');

    Notification::assertSentToTimes($this->psycho, MaterialPublishedNotification::class, 2);
    Notification::assertSentToTimes($this->director, MaterialPublishedNotification::class, 2);
    Notification::assertNotSentTo($this->teacher, MaterialPublishedNotification::class);

    $material = Material::query()->findOrFail($id);
    expect($material->removed_at)->toBeNull()
        ->and($material->removed_by_id)->toBeNull()
        ->and($material->removed_reason)->toBeNull();
});

it('does the same for an assessment\'s catalog copy', function () {
    Notification::fake();
    $assessment = Assessment::factory()->create(['group_id' => $this->group->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id]);
    Sanctum::actingAs($this->teacher);
    $id = $this->postJson("/api/v1/assessments/{$assessment->id}/material")->assertOk()->json('data.id');

    Sanctum::actingAs($this->psycho);
    $this->deleteJson("/api/v1/materials/{$id}/catalog", ['reason' => 'Repetida'])->assertOk();
    Notification::assertSentTo($this->teacher, MaterialRemovedNotification::class,
        fn (MaterialRemovedNotification $notice) => $notice->toArray($this->teacher)['reason'] === 'Repetida');

    Sanctum::actingAs($this->teacher);
    $this->postJson("/api/v1/assessments/{$assessment->id}/material")->assertOk()->assertJsonPath('data.catalog_status', 'published');
    Notification::assertSentToTimes($this->psycho, MaterialPublishedNotification::class, 2);
    Notification::assertSentToTimes($this->director, MaterialPublishedNotification::class, 2);
});

it('leaves no file behind when the upload cannot be saved', function () {
    Material::creating(fn () => throw new RuntimeException('database down'));
    Sanctum::actingAs($this->teacher);

    $this->withoutExceptionHandling();
    expect(fn () => ($this->upload)())->toThrow(RuntimeException::class);

    expect(Storage::disk('materials')->allFiles())->toBeEmpty();
});
