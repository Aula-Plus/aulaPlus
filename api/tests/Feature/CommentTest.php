<?php

use App\Models\Comment;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// docs/prompts/04-seguimiento-institucional.md §6
it('forbids a teacher with no relation to a student from commenting on them', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/students/{$student->id}/comments", ['content' => 'Observación'])
        ->assertForbidden();

    expect(Comment::count())->toBe(0);
});

it('lets a teacher who teaches the student comment on them', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/students/{$student->id}/comments", [
        'content' => 'Buen progreso en lectura.',
        'tone' => 'positive',
    ])
        ->assertCreated()
        ->assertJsonPath('data.author_id', $teacher->id)
        ->assertJsonPath('data.tone', 'positive');
});

it('lets a psychopedagogue comment on any student in their school', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson("/api/v1/students/{$student->id}/comments", ['content' => 'Seguimiento psicopedagógico.'])
        ->assertCreated();
});

// docs/prompts/04-seguimiento-institucional.md §6
it('does not show a comment restricted to psychopedagogue in the listing a teacher sees', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    Comment::factory()->forSubject($student)->visibleTo(['psychopedagogue'])->create([
        'content' => 'Nota privada de psicopedagogía',
        'author_id' => $psychopedagogue->id,
    ]);
    Comment::factory()->forSubject($student)->create([
        'content' => 'Nota visible para todos',
        'author_id' => $psychopedagogue->id,
    ]);

    Sanctum::actingAs($teacher);
    $teacherResponse = $this->getJson("/api/v1/students/{$student->id}/comments")->assertOk();
    expect($teacherResponse->json('data.*.content'))
        ->toContain('Nota visible para todos')
        ->not->toContain('Nota privada de psicopedagogía');

    Sanctum::actingAs($psychopedagogue);
    $psychopedagogueResponse = $this->getJson("/api/v1/students/{$student->id}/comments")->assertOk();
    expect($psychopedagogueResponse->json('data.*.content'))
        ->toContain('Nota privada de psicopedagogía')
        ->toContain('Nota visible para todos');
});

it('lets a teacher who leads a group comment on it, and a comment restricted to director is hidden from teachers', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/groups/{$group->id}/comments", ['content' => 'Nota de grupo'])->assertCreated();

    Comment::factory()->forSubject($group)->visibleTo(['director'])->create([
        'content' => 'Nota confidencial de dirección',
        'author_id' => $director->id,
    ]);

    $response = $this->getJson("/api/v1/groups/{$group->id}/comments")->assertOk();
    expect($response->json('data.*.content'))
        ->toContain('Nota de grupo')
        ->not->toContain('Nota confidencial de dirección');
});

it('rejects a teacher commenting on a group they do not lead', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/groups/{$group->id}/comments", ['content' => 'x'])->assertForbidden();
});
