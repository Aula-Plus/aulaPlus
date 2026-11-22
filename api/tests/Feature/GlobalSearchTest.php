<?php

use App\Models\Assessment;
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

it('requires authentication', function () {
    $this->getJson('/api/v1/search?q=ana')->assertUnauthorized();
});

it('validates the term', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->getJson('/api/v1/search')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/v1/search?q=a')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/v1/search?q='.str_repeat('a', 81))->assertUnprocessable()->assertJsonValidationErrors('q');
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

    $response = $this->getJson('/api/v1/search?q=mart')->assertOk();

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

    $this->getJson('/api/v1/search?q=geometria')->assertOk()->assertJsonCount(0, 'data.assessments');
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

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/search?q=sol')->assertOk();

    expect(collect($response->json('data.students'))->pluck('id')->all())->toBe([$student->id])
        ->and(collect($response->json('data.groups'))->pluck('id')->all())->toBe([$group->id])
        ->and(collect($response->json('data.assessments'))->pluck('id')->all())->toBe([$assessment->id]);
})->with(['director', 'psychopedagogue']);

it('ignores case and accents in both directions', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => 'Ñandú']);
    $student = searchStudentIn($group, 'JOSÉ MUÑOZ');

    $this->getJson('/api/v1/search?q=jose%20munoz')->assertOk()
        ->assertJsonPath('data.students.0.id', $student->id);
    $this->getJson('/api/v1/search?q='.urlencode('Josè'))->assertOk()
        ->assertJsonCount(0, 'data.students');
    $this->getJson('/api/v1/search?q='.urlencode('ÑANDÚ'))->assertOk()
        ->assertJsonPath('data.groups.0.id', $group->id);
    $this->getJson('/api/v1/search?q='.urlencode('nandu'))->assertOk()
        ->assertJsonPath('data.groups.0.id', $group->id);
});

it('treats LIKE wildcards in the term literally', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());
    $group = Group::factory()->create(['school_id' => $school->id]);
    searchStudentIn($group, 'Ana Gómez');

    $this->getJson('/api/v1/search?q='.urlencode('%%'))->assertOk()->assertJsonCount(0, 'data.students');
    $this->getJson('/api/v1/search?q='.urlencode('a_a'))->assertOk()->assertJsonCount(0, 'data.students');
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

    $response = $this->getJson('/api/v1/search?q=luna')->assertOk();

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

    $this->getJson('/api/v1/search?q=prueba')->assertOk()->assertJsonCount(8, 'data.students');
});

it('never logs the term', function () {
    Log::spy();
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->getJson('/api/v1/search?q=Valentina')->assertOk();

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('error');
});
