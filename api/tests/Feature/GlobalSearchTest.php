<?php

use App\Models\Assessment;
use App\Models\ClassSession;
use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

/**
 * «Buscar en Aula+» (screen 01, P01-DEC-118 / PA-134, ClickUp 1dfd3mc56kr).
 */
function searchStudentIn(Group $group, string $name, ?int $year = null): Student
{
    $student = Student::factory()->create(['school_id' => $group->school_id, 'full_name' => $name]);
    $student->groups()->attach($group, ['school_year' => $year ?? (int) ($group->school_year ?? 2026)]);

    return $student;
}

function searchAssessment(Group $group, User $teacher, string $purpose, ?Subject $subject = null): Assessment
{
    return Assessment::factory()->create([
        'school_id' => $group->school_id,
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
        'subject_id' => ($subject ?? Subject::factory()->create(['school_id' => $group->school_id]))->id,
        'purpose' => $purpose,
        'administered_at' => '2026-05-12',
    ]);
}

function searchClass(Group $group, string $title, ?Subject $subject = null, string $date = '2026-05-14'): ClassSession
{
    return ClassSession::factory()->create([
        'school_id' => $group->school_id,
        'group_id' => $group->id,
        'subject_id' => $subject?->id,
        'title' => $title,
        'date' => $date,
    ]);
}

it('requires authentication', function () {
    $this->postJson('/api/v1/search', ['q' => 'ana'])->assertUnauthorized();
});

it('validates the term', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->postJson('/api/v1/search')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->postJson('/api/v1/search', ['q' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->postJson('/api/v1/search', ['q' => str_repeat('a', 81)])->assertUnprocessable()->assertJsonValidationErrors('q');
});

it('limits a teacher to their groups, their students and their own assessments', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $colleague = User::factory()->forSchool($school)->teacher()->create();

    $mine = Group::factory()->create(['school_id' => $school->id, 'name' => 'Martes 3A', 'school_year' => 2026]);
    $other = Group::factory()->create(['school_id' => $school->id, 'name' => 'Martes 4B', 'school_year' => 2026]);
    $subject = leadGroup($mine, $teacher);
    leadGroup($mine, $colleague);
    leadGroup($other, $colleague);

    $myStudent = searchStudentIn($mine, 'Martina López');
    searchStudentIn($other, 'Martín Suárez');

    $myAssessment = searchAssessment($mine, $teacher, 'Martes: escrito de fracciones', $subject);
    // A colleague's assessment in a shared group: AssessmentPolicy::view says no.
    searchAssessment($mine, $colleague, 'Martes: oral de inglés');
    searchAssessment($other, $colleague, 'Martes: prueba de 4B');

    Sanctum::actingAs($teacher);

    $response = $this->postJson('/api/v1/search', ['q' => 'mart'])->assertOk();

    expect(collect($response->json('data.students'))->pluck('id')->all())->toBe([$myStudent->id])
        ->and(collect($response->json('data.groups'))->pluck('id')->all())->toBe([$mine->id])
        ->and(collect($response->json('data.assessments'))->pluck('id')->all())->toBe([$myAssessment->id]);
});

it('hides a teacher\'s own assessment once they no longer teach its group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    searchAssessment($group, $teacher, 'Escrito de geometría');

    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/search', ['q' => 'geometria'])->assertOk()->assertJsonCount(0, 'data.assessments');
});

it('finds only the classes a teacher could open: their subject in their groups', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $colleague = User::factory()->forSchool($school)->teacher()->create();

    $mine = Group::factory()->create(['school_id' => $school->id]);
    $other = Group::factory()->create(['school_id' => $school->id]);
    $math = leadGroup($mine, $teacher);
    $english = leadGroup($mine, $colleague);
    leadGroup($other, $colleague);

    $myClass = searchClass($mine, 'Fracciones equivalentes', $math, '2026-05-14');
    // A class without a subject: any teacher who leads the group (ClassSessionPolicy::view).
    $groupClass = searchClass($mine, 'Fracciones en la feria', null, '2026-05-10');
    // A colleague's subject in a shared group, and a group I don't teach: not mine.
    searchClass($mine, 'Fracciones en inglés', $english);
    searchClass($other, 'Fracciones de 4B');

    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/search', ['q' => 'fracciones'])->assertOk()
        ->assertJsonPath('data.classes', [
            [
                'id' => $myClass->id,
                'title' => 'Fracciones equivalentes',
                'subject' => $math->name,
                'group' => ['id' => $mine->id, 'name' => $mine->name],
                'date' => '2026-05-14',
            ],
            [
                'id' => $groupClass->id,
                'title' => 'Fracciones en la feria',
                'subject' => null,
                'group' => ['id' => $mine->id, 'name' => $mine->name],
                'date' => '2026-05-10',
            ],
        ]);

    foreach ([$myClass, $groupClass] as $class) {
        $this->getJson("/api/v1/class-sessions/{$class->id}")->assertOk();
    }
});

it('never returns a class\'s notes or content, nor matches on them', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    ClassSession::factory()->create([
        'school_id' => $school->id,
        'group_id' => $group->id,
        'title' => 'Ciclo del agua',
        'objective' => 'objetivo reservado',
        'description' => 'descripción reservada',
        'teacher_notes' => 'nota reservada',
    ]);
    ClassSession::factory()->create([
        'school_id' => $school->id,
        'group_id' => $group->id,
        'title' => 'Otra clase',
        'teacher_notes' => 'agua',
    ]);

    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());

    $response = $this->postJson('/api/v1/search', ['q' => 'agua'])->assertOk()->assertJsonCount(1, 'data.classes');

    expect(array_keys($response->json('data.classes.0')))->toBe(['id', 'title', 'subject', 'group', 'date'])
        ->and($response->getContent())->not->toContain('reservad');
});

it('gives psychopedagogy and direction the whole school, never another school', function (string $role) {
    $school = School::factory()->create();
    $otherSchool = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $user = User::factory()->forSchool($school)->{$role}()->create();

    $group = Group::factory()->create(['school_id' => $school->id, 'name' => 'Sol 1A']);
    leadGroup($group, $teacher);
    $student = searchStudentIn($group, 'Sol Rodríguez');
    $assessment = searchAssessment($group, $teacher, 'Sol y sombras');

    $foreignTeacher = User::factory()->forSchool($otherSchool)->teacher()->create();
    $foreignGroup = Group::factory()->create(['school_id' => $otherSchool->id, 'name' => 'Sol 2B']);
    leadGroup($foreignGroup, $foreignTeacher);
    searchStudentIn($foreignGroup, 'Soledad Pérez');
    searchAssessment($foreignGroup, $foreignTeacher, 'Sol naciente');
    $class = searchClass($group, 'Sol y planetas', leadGroup($group, User::factory()->forSchool($school)->teacher()->create()));
    searchClass($foreignGroup, 'Sol de otro colegio');

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/search', ['q' => 'sol'])->assertOk();

    expect(collect($response->json('data.students'))->pluck('id')->all())->toBe([$student->id])
        ->and(collect($response->json('data.groups'))->pluck('id')->all())->toBe([$group->id])
        ->and(collect($response->json('data.assessments'))->pluck('id')->all())->toBe([$assessment->id])
        ->and(collect($response->json('data.classes'))->pluck('id')->all())->toBe([$class->id]);
})->with(['director', 'psychopedagogue']);

it('ignores case and accents in both directions', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => 'Ñandú']);
    $student = searchStudentIn($group, 'JOSÉ MUÑOZ');

    $this->postJson('/api/v1/search', ['q' => 'jose munoz'])->assertOk()
        ->assertJsonPath('data.students.0.id', $student->id);
    $this->postJson('/api/v1/search', ['q' => 'Josè'])->assertOk()
        ->assertJsonCount(0, 'data.students');
    $this->postJson('/api/v1/search', ['q' => 'ÑANDÚ'])->assertOk()
        ->assertJsonPath('data.groups.0.id', $group->id);
    $this->postJson('/api/v1/search', ['q' => 'nandu'])->assertOk()
        ->assertJsonPath('data.groups.0.id', $group->id);
});

it('treats LIKE wildcards in the term literally', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $group = Group::factory()->create(['school_id' => $school->id]);
    searchStudentIn($group, 'Ana Gómez');

    $this->postJson('/api/v1/search', ['q' => '%%'])->assertOk()->assertJsonCount(0, 'data.students');
    $this->postJson('/api/v1/search', ['q' => 'a_a'])->assertOk()->assertJsonCount(0, 'data.students');
});

it('matches names and titles only, and returns only non-sensitive fields', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $level = EducationLevel::query()->where('key', 'primary')->first();
    $group = Group::factory()->create([
        'school_id' => $school->id,
        'name' => 'Luna 5A',
        'school_year' => 2026,
        'education_level_id' => $level->id,
        'group_profile' => ['notes' => 'luna perfil reservado'],
    ]);
    $subject = Subject::factory()->create(['school_id' => $school->id, 'name' => 'Matemática']);
    leadGroup($group, $teacher, $subject);
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'full_name' => 'Luna Fernández',
        'birth_date' => '2015-02-01',
        'learning_profile' => ['notes' => 'luna dato clínico'],
        'tracking_notes' => 'luna nota reservada',
    ]);
    $student->groups()->attach($group, ['school_year' => 2026]);
    // Matches only on a sensitive field, never on a name: not found.
    $hidden = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Pedro Gil', 'tracking_notes' => 'luna']);
    $hidden->groups()->attach($group, ['school_year' => 2026]);
    searchAssessment($group, $teacher, 'Luna y estrellas', $subject);

    Sanctum::actingAs(User::factory()->forSchool($school)->psychopedagogue()->create());

    $response = $this->postJson('/api/v1/search', ['q' => 'luna'])->assertOk();

    expect($response->json('data.students'))->toBe([[
        'id' => $student->id,
        'full_name' => 'Luna Fernández',
        'group' => ['id' => $group->id, 'name' => 'Luna 5A'],
    ]])->and($response->json('data.groups'))->toBe([[
        'id' => $group->id,
        'name' => 'Luna 5A',
        'level' => $level->label,
        'school_year' => 2026,
    ]])->and(array_keys($response->json('data.assessments.0')))
        ->toBe(['id', 'title', 'type', 'subject', 'group', 'date'])
        ->and($response->json('data.assessments.0.subject'))->toBe('Matemática')
        ->and($response->json('data.assessments.0.group'))->toBe(['id' => $group->id, 'name' => 'Luna 5A'])
        ->and($response->json('data.assessments.0.date'))->toBe('2026-05-12');

    $body = $response->getContent();
    foreach (['reservad', 'clínico', 'clinico', 'birth_date', 'learning_profile', 'tracking_notes', 'group_profile', 'Pedro'] as $needle) {
        expect($body)->not->toContain($needle);
    }
});

it('caps the results per type', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $group = Group::factory()->create(['school_id' => $school->id]);
    foreach (range(1, 12) as $i) {
        searchStudentIn($group, "Alumno Prueba {$i}");
    }

    $this->postJson('/api/v1/search', ['q' => 'prueba'])->assertOk()->assertJsonCount(8, 'data.students');
});

it('never logs the term', function () {
    Log::spy();
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->postJson('/api/v1/search', ['q' => 'Valentina'])->assertOk();

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('error');
});

it('rejects the term in the URL: the search is a POST with the term in the body', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $group = Group::factory()->create(['school_id' => $school->id]);
    searchStudentIn($group, 'Valentina Pérez');

    $this->getJson('/api/v1/search?q=valentina')->assertMethodNotAllowed();
    // A `q` in the query string of the POST is ignored, not read.
    $this->postJson('/api/v1/search?q=valentina')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->postJson('/api/v1/search', ['q' => 'valentina'])->assertOk()->assertJsonCount(1, 'data.students');
});

/**
 * An assessment without a purpose (Eitán 26/11): «tipo · materia · grupo · fecha».
 */
function untitledSearchAssessment(Group $group, User $teacher, Subject $subject, string $type, string $date, ?string $purpose = null): Assessment
{
    return Assessment::factory()->create([
        'school_id' => $group->school_id,
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
        'type' => $type,
        'purpose' => $purpose,
        'administered_at' => $date,
    ]);
}

it('finds an assessment without a purpose by any word of its composed name', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => '5.°A']);
    $math = leadGroup($group, $teacher);
    $math->update(['name' => 'Matemática']);
    $written = untitledSearchAssessment($group, $teacher, $math, 'written', '2026-11-12');
    $blank = untitledSearchAssessment($group, $teacher, $math, 'oral', '2026-10-03', '   ');

    Sanctum::actingAs($teacher);
    $ids = fn (string $q) => collect($this->postJson('/api/v1/search', ['q' => $q])->assertOk()->json('data.assessments'))->pluck('id')->all();

    expect($ids('Escrito'))->toBe([$written->id])
        ->and($ids('escrita'))->toBe([$written->id])
        ->and($ids('matematica'))->toBe([$written->id, $blank->id])
        ->and($ids('5.°A'))->toBe([$written->id, $blank->id])
        ->and($ids('12/11'))->toBe([$written->id])
        ->and($ids('12/11/2026'))->toBe([$written->id])
        ->and($ids('12/11/2025'))->toBe([])
        ->and($ids('Escrito · Matemática · 5.°A · 12/11'))->toBe([$written->id])
        ->and($ids('oral 5.°A'))->toBe([$blank->id])
        // Every word must match: there is no oral on 12/11.
        ->and($ids('oral 12/11'))->toBe([])
        ->and($ids('31/02x'))->toBe([]);

    // The SPA composes the name: no title, plus its parts.
    $first = $this->postJson('/api/v1/search', ['q' => 'escrito'])->json('data.assessments.0');
    expect($first)->toMatchArray([
        'title' => null,
        'type' => 'written',
        'subject' => 'Matemática',
        'group' => ['id' => $group->id, 'name' => '5.°A'],
        'date' => '2026-11-12',
    ]);
});

it('keeps finding assessments with a purpose by their title only', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => '5.°A']);
    $math = leadGroup($group, $teacher);
    $math->update(['name' => 'Matemática']);
    $titled = untitledSearchAssessment($group, $teacher, $math, 'written', '2026-11-12', 'Fracciones y decimales');

    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/search', ['q' => 'fracciones'])->assertOk()
        ->assertJsonPath('data.assessments.0.id', $titled->id)
        ->assertJsonPath('data.assessments.0.title', 'Fracciones y decimales');
    foreach (['escrito', 'matematica', '12/11'] as $q) {
        $this->postJson('/api/v1/search', ['q' => $q])->assertOk()->assertJsonCount(0, 'data.assessments');
    }
});

it('scopes untitled assessments like the rest: not a colleague\'s, not another school\'s', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $colleague = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $math = leadGroup($group, $teacher);
    $english = leadGroup($group, $colleague);
    $mine = untitledSearchAssessment($group, $teacher, $math, 'written', '2026-11-12');
    untitledSearchAssessment($group, $colleague, $english, 'written', '2026-11-12');

    $foreignSchool = School::factory()->create();
    $foreignTeacher = User::factory()->forSchool($foreignSchool)->teacher()->create();
    $foreignGroup = Group::factory()->create(['school_id' => $foreignSchool->id]);
    $foreignSubject = leadGroup($foreignGroup, $foreignTeacher);
    untitledSearchAssessment($foreignGroup, $foreignTeacher, $foreignSubject, 'written', '2026-11-12');

    Sanctum::actingAs($teacher);
    $this->postJson('/api/v1/search', ['q' => 'escrito'])->assertOk()
        ->assertJsonCount(1, 'data.assessments')
        ->assertJsonPath('data.assessments.0.id', $mine->id);

    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $this->postJson('/api/v1/search', ['q' => '12/11'])->assertOk()->assertJsonCount(2, 'data.assessments');
});
