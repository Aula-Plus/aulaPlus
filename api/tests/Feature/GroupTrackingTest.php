<?php

use App\Models\Accommodation;
use App\Models\Alert;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// docs/prompts/04-seguimiento-institucional.md §3 and §7: the group tracking
// view must never expose clinical detail, only counts — verified here for
// BOTH a teacher and a director/psychopedagogue, since the per-student
// summary here is counts-only for every role (full detail lives behind
// /students/{id}/tracking, which does its own per-role gating).
it('exposes only counts/booleans per student, never clinical detail, to a teacher leading the group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Ana Gómez']);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Accommodation::factory()->create(['student_id' => $student->id, 'active' => true]);
    Alert::factory()->create(['student_id' => $student->id, 'resolved' => false]);
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk();

    $studentSummary = collect($response->json('data.students'))->firstWhere('id', $student->id);

    expect($studentSummary)
        ->toHaveKeys(['id', 'full_name', 'open_alerts_count', 'has_active_accommodations'])
        ->and($studentSummary['open_alerts_count'])->toBe(1)
        ->and($studentSummary['has_active_accommodations'])->toBeTrue()
        ->and($studentSummary)->not->toHaveKey('accommodations')
        ->and($studentSummary)->not->toHaveKey('alerts')
        ->and(json_encode($studentSummary))->not->toContain('description');
});

it('exposes the same counts-only shape to a director', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Sanctum::actingAs($director);

    $response = $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk();

    $studentSummary = collect($response->json('data.students'))->firstWhere('id', $student->id);

    expect($studentSummary)->toHaveKeys(['id', 'full_name', 'open_alerts_count', 'has_active_accommodations'])
        ->and($studentSummary)->not->toHaveKey('accommodations')
        ->and($studentSummary)->not->toHaveKey('alerts');
});

it('reports the group trend: assessments and comments count within the period', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Assessment::factory()->create(['group_id' => $group->id]);
    Comment::factory()->forSubject($group)->create();
    Sanctum::actingAs($director);

    $response = $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk();

    $response->assertJsonPath('data.trend.assessments_count', 1)
        ->assertJsonPath('data.trend.comments_count', 1);
});

// docs/prompts/21-perfil-de-grupo.md §3: the student list is ordered
// alphabetically by full_name, never by any indicator (open alerts,
// accommodations) — the group profile lists students, it does not rank them.
it('lists the group students alphabetically by full_name, not by insertion order', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    // Attach out of alphabetical order so insertion order != alphabetical.
    $zoe = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Zoe Álvarez']);
    $ana = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Ana Gómez']);
    $marco = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Marco Díaz']);
    $zoe->groups()->attach($group, ['school_year' => now()->year]);
    $ana->groups()->attach($group, ['school_year' => now()->year]);
    $marco->groups()->attach($group, ['school_year' => now()->year]);

    Sanctum::actingAs($director);
    $response = $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk();

    $names = collect($response->json('data.students'))->pluck('full_name')->all();
    expect($names)->toBe(['Ana Gómez', 'Marco Díaz', 'Zoe Álvarez']);
});

it('forbids a teacher who does not lead the group from viewing its tracking page', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertForbidden();
});

// docs/prompts/19-comentarios-alcance.md §2: the group comments count ("el
// número ancla") is exclusive to psychopedagogue/director. A teacher leading
// the group still sees the page and the assessments count, but the
// comments_count KEY is omitted entirely — not a 0, not a null.
it('omits the comments_count key for a teacher leading the group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    Comment::factory()->forSubject($group)->create();
    Sanctum::actingAs($teacher);

    $response = $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk();

    $trend = $response->json('data.trend');
    expect($trend)
        ->toHaveKey('assessments_count')
        ->and($trend)->not->toHaveKey('comments_count');
});

// Regression for §2: gating who *receives* the key must not change what it
// *counts*. Psychopedagogue/director still get the full period total, private
// comments (visible_to-restricted and author_only) included.
it('still gives psychopedagogue/director the full comments_count, private comments included', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    Comment::factory()->forSubject($group)->create(['author_id' => $psychopedagogue->id]);
    Comment::factory()->forSubject($group)->visibleTo(['psychopedagogue'])->create(['author_id' => $psychopedagogue->id]);
    Comment::factory()->forSubject($group)->authorOnly()->create(['author_id' => $psychopedagogue->id]);

    Sanctum::actingAs($director);
    $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk()
        ->assertJsonPath('data.trend.comments_count', 3);

    Sanctum::actingAs($psychopedagogue);
    $this->getJson("/api/v1/groups/{$group->id}/tracking")->assertOk()
        ->assertJsonPath('data.trend.comments_count', 3);
});
