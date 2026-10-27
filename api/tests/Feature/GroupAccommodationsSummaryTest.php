<?php

use App\Models\Accommodation;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// docs/prompts/21-perfil-de-grupo.md §1: active accommodations of the group's
// students, aggregated by `type`, counting DISTINCT students (never rows) and
// only effective ones — "agregado por tipo, nunca nómina".

it('aggregates effective accommodations by type, counting distinct students not rows', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    $ana = Student::factory()->create(['school_id' => $school->id]);
    $beto = Student::factory()->create(['school_id' => $school->id]);
    $ana->groups()->attach($group, ['school_year' => now()->year]);
    $beto->groups()->attach($group, ['school_year' => now()->year]);

    // Ana has TWO "tiempo extra" rows — must still count as ONE student.
    Accommodation::factory()->create(['student_id' => $ana->id, 'type' => 'tiempo extra', 'category' => 'access', 'active' => true]);
    Accommodation::factory()->create(['student_id' => $ana->id, 'type' => 'tiempo extra', 'category' => 'access', 'active' => true]);
    // Beto also has "tiempo extra" → distinct student count for that type = 2.
    Accommodation::factory()->create(['student_id' => $beto->id, 'type' => 'tiempo extra', 'category' => 'access', 'active' => true]);
    // A different type on Ana → its own bucket, 1 student.
    Accommodation::factory()->create(['student_id' => $ana->id, 'type' => 'ortografía no puntúa', 'category' => 'criteria', 'active' => true]);

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/groups/{$group->id}/accommodations-summary")->assertOk();

    $data = collect($response->json());

    expect($data->firstWhere('type', 'tiempo extra'))
        ->toMatchArray(['type' => 'tiempo extra', 'category' => 'access', 'student_count' => 2]);
    expect($data->firstWhere('type', 'ortografía no puntúa'))
        ->toMatchArray(['type' => 'ortografía no puntúa', 'category' => 'criteria', 'student_count' => 1]);
});

it('excludes accommodations that are not effective', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    // active=false → not effective.
    Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'inactiva', 'active' => false]);
    // requires external approval, not yet approved → not effective.
    Accommodation::factory()->create([
        'student_id' => $student->id,
        'type' => 'pendiente de aprobación',
        'active' => true,
        'requires_external_approval' => true,
        'approved' => null,
    ]);
    // effective control row.
    Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'lectura asistida', 'active' => true]);

    Sanctum::actingAs($director);
    $data = collect($this->getJson("/api/v1/groups/{$group->id}/accommodations-summary")->assertOk()->json());

    expect($data->pluck('type')->all())->toBe(['lectura asistida']);
});

it('never names a student in the summary', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Secreto Nombre']);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'tiempo extra', 'active' => true]);

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/groups/{$group->id}/accommodations-summary")->assertOk();

    expect($response->getContent())
        ->not->toContain('Secreto Nombre')
        ->not->toContain('student_id');
});

it('is available to a teacher leading the group, with the general accommodations', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'tiempo extra', 'active' => true]);

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/groups/{$group->id}/accommodations-summary")
        ->assertOk()
        ->assertJsonFragment(['type' => 'tiempo extra', 'student_count' => 1]);
});

// ClickUp 1dfd3mc2wwy: for a teacher the table counts the general accommodations
// plus those of the subjects they teach (Accommodation::scopeVisibleTo, as in the
// student file), so it agrees with the student list. School-wide roles see all.
it('counts for a teacher only general accommodations and those of the subjects they teach', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $math = Subject::factory()->create(['school_id' => $school->id]);
    $spanish = Subject::factory()->create(['school_id' => $school->id]);
    $mathTeacher = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $mathTeacher, $math);
    $spanishTeacher = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $spanishTeacher, $spanish);
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();

    $ana = Student::factory()->create(['school_id' => $school->id]);
    $beto = Student::factory()->create(['school_id' => $school->id]);
    $ana->groups()->attach($group, ['school_year' => now()->year]);
    $beto->groups()->attach($group, ['school_year' => now()->year]);

    Accommodation::factory()->create(['student_id' => $ana->id, 'type' => 'ortografía no puntúa', 'active' => true])
        ->subjects()->attach($spanish);
    Accommodation::factory()->create(['student_id' => $beto->id, 'type' => 'calculadora', 'active' => true])
        ->subjects()->attach($math);
    Accommodation::factory()->create(['student_id' => $beto->id, 'type' => 'tiempo extra', 'active' => true]);

    $types = function (User $viewer) use ($group): array {
        Sanctum::actingAs($viewer);

        return collect($this->getJson("/api/v1/groups/{$group->id}/accommodations-summary")->assertOk()->json())
            ->pluck('student_count', 'type')
            ->all();
    };

    expect($types($mathTeacher))->toEqual(['calculadora' => 1, 'tiempo extra' => 1])
        ->and($types($spanishTeacher))->toEqual(['ortografía no puntúa' => 1, 'tiempo extra' => 1])
        ->and($types($director))->toEqual(['calculadora' => 1, 'ortografía no puntúa' => 1, 'tiempo extra' => 1])
        ->and($types($psychopedagogue))->toEqual(['calculadora' => 1, 'ortografía no puntúa' => 1, 'tiempo extra' => 1]);
});

it('forbids a teacher who does not lead the group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/groups/{$group->id}/accommodations-summary")->assertForbidden();
});
