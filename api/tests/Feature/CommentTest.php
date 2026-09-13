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

// docs/prompts/19-comentarios-alcance.md §1 + §3: "solo quien escribe" is
// stricter than any role — an author_only comment is invisible to EVERY other
// user, including director/psychopedagogue who otherwise see school-wide.
it('shows an author_only comment only to its author, hidden from every other role', function () {
    $school = School::factory()->create();
    $teacherA = User::factory()->forSchool($school)->teacher()->create();
    $teacherB = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();

    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacherA);
    $group->teachers()->attach($teacherB);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    Comment::factory()->forSubject($student)->authorOnly()->create([
        'content' => 'Nota privada solo para mí',
        'author_id' => $teacherA->id,
    ]);

    // The author sees it.
    Sanctum::actingAs($teacherA);
    expect($this->getJson("/api/v1/students/{$student->id}/comments")->assertOk()->json('data.*.content'))
        ->toContain('Nota privada solo para mí');

    // A colleague who shares the teacher role and also teaches the student does not.
    Sanctum::actingAs($teacherB);
    expect($this->getJson("/api/v1/students/{$student->id}/comments")->assertOk()->json('data.*.content'))
        ->not->toContain('Nota privada solo para mí');

    // Neither do the school-wide roles.
    Sanctum::actingAs($director);
    expect($this->getJson("/api/v1/students/{$student->id}/comments")->assertOk()->json('data.*.content'))
        ->not->toContain('Nota privada solo para mí');

    Sanctum::actingAs($psychopedagogue);
    expect($this->getJson("/api/v1/students/{$student->id}/comments")->assertOk()->json('data.*.content'))
        ->not->toContain('Nota privada solo para mí');
});

it('forces visible_to to null when author_only is set, and echoes author_only back', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psychopedagogue);

    // Even though visible_to is sent, author_only wins and visible_to is stored null.
    $this->postJson("/api/v1/students/{$student->id}/comments", [
        'content' => 'Recordatorio para mí',
        'author_only' => true,
        'visible_to' => ['director'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.author_only', true)
        ->assertJsonPath('data.visible_to', null);

    expect(Comment::first())
        ->author_only->toBeTrue()
        ->visible_to->toBeNull();
});

// docs/prompts/19-comentarios-alcance.md §3: regression — for author_only=false
// comments, scopeVisibleToRole and isVisibleTo give the SAME answer as before
// (Sesión 4/8 behaviour), and stay in sync with each other.
it('keeps the role-based visibility rule unchanged for author_only=false comments', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    $public = Comment::factory()->forSubject($student)->create(['author_id' => $psychopedagogue->id]);
    $restricted = Comment::factory()->forSubject($student)->visibleTo(['psychopedagogue'])->create([
        'author_id' => $psychopedagogue->id,
    ]);

    // Plain-PHP method (used by the student tracking view).
    expect($public->isVisibleTo($teacher))->toBeTrue()
        ->and($restricted->isVisibleTo($teacher))->toBeFalse()
        ->and($restricted->isVisibleTo($psychopedagogue))->toBeTrue();

    // DB scope (used by the list endpoints) agrees with the PHP method.
    $teacherIds = Comment::query()->visibleToRole($teacher)->pluck('id');
    expect($teacherIds)->toContain($public->id)->not->toContain($restricted->id);

    $psychopedagogueIds = Comment::query()->visibleToRole($psychopedagogue)->pluck('id');
    expect($psychopedagogueIds)->toContain($public->id)->toContain($restricted->id);
});

// docs/prompts/19-comentarios-alcance.md §1 regression: an explicit empty
// `visible_to: []` must not diverge between the two visibility paths.
// isVisibleTo() treats empty as visible-to-all, but scopeVisibleToRole() only
// matches `visible_to IS NULL`; a stored `[]` would show in the tracking view
// yet vanish from the list endpoint. The store request normalizes `[]` to null
// so both paths agree.
it('normalizes an empty visible_to array to null so both visibility paths agree', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $group->teachers()->attach($teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    Sanctum::actingAs($psychopedagogue);
    $this->postJson("/api/v1/students/{$student->id}/comments", [
        'content' => 'Sin restricción de rol',
        'visible_to' => [],
    ])
        ->assertCreated()
        ->assertJsonPath('data.visible_to', null);

    $comment = Comment::first();
    expect($comment->visible_to)->toBeNull();

    // Both paths agree: visible to a teacher who is not the author.
    expect($comment->isVisibleTo($teacher))->toBeTrue()
        ->and(Comment::query()->visibleToRole($teacher)->pluck('id'))->toContain($comment->id);

    // And the list endpoint returns it to that teacher.
    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.content', 'Sin restricción de rol');
});
