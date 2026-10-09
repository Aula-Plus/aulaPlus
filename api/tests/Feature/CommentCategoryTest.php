<?php

use App\Models\Alert;
use App\Models\Comment;
use App\Models\CommentCategory;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function categoryId(School $school, string $name): int
{
    return CommentCategory::withoutGlobalScopes()->where('school_id', $school->id)->where('name', $name)->value('id');
}

function studentWithTeacher(School $school, User $teacher): Student
{
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    return $student;
}

it('gives every new school the default categories', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    Sanctum::actingAs($teacher);

    $names = collect($this->getJson('/api/v1/comment-categories')->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Académico', 'Conductual', 'Social', 'Emocional', 'Familiar', 'Otro']);
});

it('does not list the categories to a user without a staff role', function () {
    Sanctum::actingAs(User::factory()->forSchool(School::factory()->create())->create());

    $this->getJson('/api/v1/comment-categories')->assertForbidden();
});

it('lets only a director edit the categories, and only their own school\'s', function () {
    $school = School::factory()->create();
    $other = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $psico = User::factory()->forSchool($school)->psychopedagogue()->create();

    Sanctum::actingAs($psico);
    $this->postJson('/api/v1/comment-categories', ['name' => 'Salud'])->assertForbidden();

    Sanctum::actingAs($director);
    $id = $this->postJson('/api/v1/comment-categories', ['name' => 'Salud'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/comment-categories', ['name' => 'Salud'])->assertJsonValidationErrors('name');
    $this->putJson("/api/v1/comment-categories/{$id}", ['name' => 'Bienestar'])->assertOk()->assertJsonPath('data.name', 'Bienestar');
    $this->deleteJson("/api/v1/comment-categories/{$id}")->assertNoContent();

    $foreign = categoryId($other, 'Social');
    $this->putJson("/api/v1/comment-categories/{$foreign}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/comment-categories/{$foreign}")->assertNotFound();
});

it('tags a comment with several optional categories of its own school only', function () {
    $school = School::factory()->create();
    $other = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = studentWithTeacher($school, $teacher);
    Sanctum::actingAs($teacher);
    $url = "/api/v1/students/{$student->id}/comments";

    $response = $this->postJson($url, [
        'content' => 'Se puso a llorar cuando le devolví el escrito',
        'category_ids' => [categoryId($school, 'Emocional'), categoryId($school, 'Académico')],
    ])->assertCreated();
    expect(collect($response->json('data.categories'))->pluck('name')->all())->toEqualCanonicalizing(['Emocional', 'Académico']);

    $this->postJson($url, ['content' => 'x', 'category_ids' => [categoryId($other, 'Social')]])
        ->assertJsonValidationErrors('category_ids.0');

    // The listing carries the categories; a comment without any stays valid.
    $this->postJson($url, ['content' => 'sin categoría'])->assertCreated();
    $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
});

it('does not accept the old tone field any more', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = studentWithTeacher($school, $teacher);
    Sanctum::actingAs($teacher);

    $this->postJson("/api/v1/students/{$student->id}/comments", ['content' => 'x', 'tone' => 'concerning'])
        ->assertCreated()
        ->assertJsonMissingPath('data.tone');
});

it('never opens an alert from comments', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $student = studentWithTeacher($school, $teacher);
    $social = categoryId($school, 'Social');

    Comment::factory()->count(6)->forSubject($student)->create(['author_id' => $teacher->id])
        ->each(fn (Comment $comment) => $comment->categories()->attach($social));

    expect(Alert::withoutGlobalScopes()->count())->toBe(0);
});

it('flags a trend only to psychopedagogy and direction, from the school\'s own threshold', function () {
    $school = School::factory()->create(['comment_trend_min_count' => 4, 'comment_trend_days' => 21]);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $otherTeachers = User::factory()->count(3)->forSchool($school)->teacher()->create();
    $psico = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = studentWithTeacher($school, $teacher);
    $social = categoryId($school, 'Social');

    foreach ([$teacher, ...$otherTeachers] as $i => $author) {
        Comment::factory()->forSubject($student)->create(['author_id' => $author->id, 'created_at' => now()->subDays($i * 5)])
            ->categories()->attach($social);
    }

    Sanctum::actingAs($psico);
    $trends = $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk()->json('data.comment_trends');
    expect($trends)->toHaveCount(1)
        ->and($trends[0])->toMatchArray(['category_name' => 'Social', 'count' => 4, 'authors' => 4, 'days' => 21]);

    Sanctum::actingAs($director);
    $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk()->assertJsonCount(1, 'data.comment_trends');

    // The teacher never receives the mark (the number anchors).
    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk()->assertJsonMissingPath('data.comment_trends');
});

it('does not flag a trend below the threshold, outside the window, or counting comments the viewer cannot see', function () {
    $school = School::factory()->create(['comment_trend_min_count' => 3, 'comment_trend_days' => 10]);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psico = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = studentWithTeacher($school, $teacher);
    $social = categoryId($school, 'Social');

    // Two recent + one too old = below threshold.
    foreach ([1, 2, 30] as $daysAgo) {
        Comment::factory()->forSubject($student)->create(['author_id' => $teacher->id, 'created_at' => now()->subDays($daysAgo)])
            ->categories()->attach($social);
    }
    Sanctum::actingAs($psico);
    $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk()->assertJsonCount(0, 'data.comment_trends');

    // A third recent one, but private to its author (the psico): the director cannot count it.
    Comment::factory()->forSubject($student)->authorOnly()->create(['author_id' => $psico->id, 'created_at' => now()->subDay()])
        ->categories()->attach($social);
    Sanctum::actingAs($director);
    $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk()->assertJsonCount(0, 'data.comment_trends');

    Sanctum::actingAs($psico);
    $this->getJson("/api/v1/students/{$student->id}/tracking")->assertOk()->assertJsonCount(1, 'data.comment_trends');
});

it('lets psychopedagogy read and only direction change the trend threshold', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psico = User::factory()->forSchool($school)->psychopedagogue()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    Sanctum::actingAs($teacher);
    $this->getJson('/api/v1/comment-trend-settings')->assertForbidden();

    Sanctum::actingAs($psico);
    $this->getJson('/api/v1/comment-trend-settings')->assertOk()->assertJsonPath('data', ['min_count' => 4, 'days' => 21]);
    $this->putJson('/api/v1/comment-trend-settings', ['min_count' => 5, 'days' => 30])->assertForbidden();

    Sanctum::actingAs($director);
    $this->putJson('/api/v1/comment-trend-settings', ['min_count' => 1, 'days' => 30])->assertJsonValidationErrors('min_count');
    $this->putJson('/api/v1/comment-trend-settings', ['min_count' => 5, 'days' => 30])->assertOk()->assertJsonPath('data', ['min_count' => 5, 'days' => 30]);
    expect($school->refresh()->comment_trend_min_count)->toBe(5);
});

it('does not let a teacher create, rename or delete categories', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $social = categoryId($school, 'Social');
    Sanctum::actingAs($teacher);

    $this->postJson('/api/v1/comment-categories', ['name' => 'Salud'])->assertForbidden();
    $this->putJson("/api/v1/comment-categories/{$social}", ['name' => 'x'])->assertForbidden();
    $this->deleteJson("/api/v1/comment-categories/{$social}")->assertForbidden();

    expect(CommentCategory::withoutGlobalScopes()->find($social)->name)->toBe('Social');
});

it('tags a group comment only with its own school\'s categories', function () {
    $school = School::factory()->create();
    $other = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    Sanctum::actingAs($teacher);
    $url = "/api/v1/groups/{$group->id}/comments";

    $this->postJson($url, ['content' => 'x', 'category_ids' => [categoryId($other, 'Social')]])
        ->assertJsonValidationErrors('category_ids.0');

    $this->postJson($url, ['content' => 'x', 'category_ids' => [categoryId($school, 'Social')]])
        ->assertCreated()
        ->assertJsonPath('data.categories.0.name', 'Social');
});

it('does not let a teacher change the trend threshold', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->teacher()->create());

    $this->putJson('/api/v1/comment-trend-settings', ['min_count' => 5, 'days' => 30])->assertForbidden();
    expect($school->refresh()->comment_trend_min_count)->toBe(4);
});
