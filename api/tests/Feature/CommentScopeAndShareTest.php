<?php

use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\CommentCategory;
use App\Models\CommentShare;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

// ClickUp 86e3qkwnv: visibility scopes, the author's private signal and
// psychopedagogy's «Compartir con» extracts.

/**
 * A school with one student taught by `$teacher`, plus a psychopedagogue and
 * a director. Threshold for trends/private signal: 3 in 10 days.
 *
 * @return array{school: School, student: Student, teacher: User, psico: User, director: User}
 */
function scopeFixture(): array
{
    $school = School::factory()->create(['comment_trend_min_count' => 3, 'comment_trend_days' => 10]);
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    leadGroup($group, $teacher);
    $student = Student::factory()->create(['school_id' => $school->id]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    return [
        'school' => $school,
        'student' => $student,
        'teacher' => $teacher,
        'psico' => User::factory()->forSchool($school)->psychopedagogue()->create(),
        'director' => User::factory()->forSchool($school)->director()->create(),
    ];
}

/** @return list<string> */
function listedCommentContents(Student $student): array
{
    return test()->getJson("/api/v1/students/{$student->id}/comments")->assertOk()->json('data.*.content');
}

/** @return array<string, mixed> */
function trackingFor(Student $student): array
{
    return test()->getJson("/api/v1/students/{$student->id}/tracking")->assertOk()->json('data');
}

it('lets psychopedagogy read every scope except other people\'s "solo quien escribe"', function () {
    ['student' => $student, 'teacher' => $teacher, 'psico' => $psico, 'director' => $director] = scopeFixture();

    Comment::factory()->forSubject($student)->create(['author_id' => $teacher->id, 'content' => 'Todos']);
    Comment::factory()->forSubject($student)->visibleTo(Comment::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY)
        ->create(['author_id' => $director->id, 'content' => 'Dirección y psico']);
    // A legacy "solo dirección" row that predates the migration still reaches psychopedagogy.
    Comment::factory()->forSubject($student)->visibleTo(['director'])
        ->create(['author_id' => $director->id, 'content' => 'Legado solo dirección']);
    Comment::factory()->forSubject($student)->visibleTo(Comment::SCOPE_PSYCHOPEDAGOGY_ONLY)
        ->create(['author_id' => $teacher->id, 'content' => 'Solo psico']);
    Comment::factory()->forSubject($student)->authorOnly()
        ->create(['author_id' => $teacher->id, 'content' => 'Privada del docente']);

    Sanctum::actingAs($psico);
    expect(listedCommentContents($student))
        ->toContain('Todos', 'Dirección y psico', 'Legado solo dirección', 'Solo psico')
        ->not->toContain('Privada del docente');
    // The tracking view's in-PHP filter agrees with the query scope.
    expect(collect(trackingFor($student)['recent_comments'])->pluck('content')->all())
        ->toContain('Legado solo dirección', 'Solo psico')
        ->not->toContain('Privada del docente');
});

it('does not let direction read «Solo Psicopedagogía», nor a teacher «Dirección y Psicopedagogía»', function () {
    ['student' => $student, 'teacher' => $teacher, 'psico' => $psico, 'director' => $director] = scopeFixture();

    Comment::factory()->forSubject($student)->create(['author_id' => $psico->id, 'content' => 'Todos']);
    Comment::factory()->forSubject($student)->visibleTo(Comment::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY)
        ->create(['author_id' => $psico->id, 'content' => 'Dirección y psico']);
    Comment::factory()->forSubject($student)->visibleTo(Comment::SCOPE_PSYCHOPEDAGOGY_ONLY)
        ->create(['author_id' => $psico->id, 'content' => 'Solo psico']);

    Sanctum::actingAs($director);
    expect(listedCommentContents($student))
        ->toContain('Todos', 'Dirección y psico')
        ->not->toContain('Solo psico');

    Sanctum::actingAs($teacher);
    expect(listedCommentContents($student))
        ->toContain('Todos')
        ->not->toContain('Dirección y psico')
        ->not->toContain('Solo psico');
});

it('stores the old "solo dirección" as «Dirección y Psicopedagogía» and rejects free role combinations', function () {
    ['student' => $student, 'director' => $director] = scopeFixture();
    Sanctum::actingAs($director);

    $this->postJson("/api/v1/students/{$student->id}/comments", ['content' => 'x', 'visible_to' => ['director']])
        ->assertCreated()
        ->assertJsonPath('data.visible_to', Comment::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY);

    $this->postJson("/api/v1/students/{$student->id}/comments", [
        'content' => 'x', 'visible_to' => ['psychopedagogue', 'director'],
    ])->assertCreated()->assertJsonPath('data.visible_to', Comment::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY);

    $this->postJson("/api/v1/students/{$student->id}/comments", ['content' => 'x', 'visible_to' => ['teacher']])
        ->assertJsonValidationErrors('visible_to');
    $this->postJson("/api/v1/students/{$student->id}/comments", ['content' => 'x', 'visible_to' => ['teacher', 'director']])
        ->assertJsonValidationErrors('visible_to');
});

it('maps stored visibility values to the new scopes without widening anything but psychopedagogy', function () {
    ['student' => $student, 'teacher' => $teacher] = scopeFixture();
    $make = fn (?array $visibleTo) => Comment::factory()->forSubject($student)
        ->create(['author_id' => $teacher->id, 'visible_to' => $visibleTo])->id;

    $directorOnly = $make(['director']);
    $psicoOnly = $make(['psychopedagogue']);
    $allRoles = $make(['teacher', 'director', 'psychopedagogue']);
    $legacyTeacher = $make(['teacher']);
    $everyone = $make(null);

    (require database_path('migrations/2026_10_18_000001_map_comment_visibility_to_new_scopes.php'))->up();

    $stored = fn (int $id) => json_decode((string) DB::table('comments')->where('id', $id)->value('visible_to'), true);
    expect($stored($directorOnly))->toBe(['director', 'psychopedagogue'])
        ->and($stored($psicoOnly))->toBe(['psychopedagogue'])
        ->and($stored($allRoles))->toBeNull()
        // A free legacy combination is left as it was: never widened.
        ->and($stored($legacyTeacher))->toBe(['teacher'])
        ->and($stored($everyone))->toBeNull();
});

it('shows a teacher their private signal once their own private notes reach the threshold in one category, and nobody else', function () {
    ['school' => $school, 'student' => $student, 'teacher' => $teacher, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $colleague = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($student->groups()->first(), $colleague);
    [$social, $academic] = CommentCategory::withoutGlobalScopes()->where('school_id', $school->id)->limit(2)->pluck('id')->all();
    $private = fn (int $daysAgo, array $categories) => Comment::factory()->forSubject($student)->authorOnly()
        ->create(['author_id' => $teacher->id, 'created_at' => now()->subDays($daysAgo)])
        ->categories()->attach($categories);

    // Two recent Social + one outside the 10-day window, one in another
    // category and one without category: no single category reaches 3.
    $private(1, [$social]);
    $private(2, [$social]);
    $private(30, [$social]);
    $private(2, [$academic]);
    $private(2, []);
    Sanctum::actingAs($teacher);
    expect(trackingFor($student)['private_notes_signal'])->toBeFalse();

    // A third recent Social one (also tagged Académico) reaches it.
    $private(3, [$social, $academic]);
    expect(trackingFor($student)['private_notes_signal'])->toBeTrue();

    foreach ([$colleague, $psico, $director] as $other) {
        Sanctum::actingAs($other);
        expect(trackingFor($student)['private_notes_signal'])->toBeFalse();
    }
});

it('does not count psychopedagogy\'s or direction\'s own private notes in their common trend', function () {
    ['school' => $school, 'student' => $student, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $category = CommentCategory::withoutGlobalScopes()->where('school_id', $school->id)->value('id');

    foreach ([$psico, $director] as $author) {
        foreach ([1, 2, 3] as $daysAgo) {
            Comment::factory()->forSubject($student)->authorOnly()
                ->create(['author_id' => $author->id, 'created_at' => now()->subDays($daysAgo)])
                ->categories()->attach($category);
        }
    }

    foreach ([$psico, $director] as $viewer) {
        Sanctum::actingAs($viewer);
        $tracking = trackingFor($student);
        // Not in the common mark…
        expect($tracking['comment_trends'])->toBe([])
            // …only in the author's own private signal.
            ->and($tracking['private_notes_signal'])->toBeTrue();
    }
});

it('lets psychopedagogy share an extract: the recipient reads only the extract, never the original', function () {
    ['student' => $student, 'teacher' => $teacher, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $original = Comment::factory()->forSubject($student)->visibleTo(Comment::SCOPE_PSYCHOPEDAGOGY_ONLY)
        ->create(['author_id' => $psico->id, 'content' => 'Original reservado']);
    $other = Comment::factory()->forSubject($student)->visibleTo(Comment::SCOPE_PSYCHOPEDAGOGY_ONLY)
        ->create(['author_id' => $psico->id, 'content' => 'Otra nota reservada']);

    Sanctum::actingAs($psico);
    $this->postJson("/api/v1/comments/{$original->id}/shares", [
        'content' => 'Extracto para el docente',
        'recipient_ids' => [$teacher->id],
    ])
        ->assertCreated()
        ->assertJsonPath('data.content', 'Extracto para el docente')
        ->assertJsonPath('data.comment_id', $original->id)
        ->assertJsonPath('data.recipients.0.id', $teacher->id);

    Sanctum::actingAs($teacher);
    $shares = $this->getJson("/api/v1/students/{$student->id}/comment-shares")->assertOk();
    expect($shares->json('data.*.content'))->toBe(['Extracto para el docente']);
    // No link to the original and no recipient list for a recipient.
    expect($shares->json('data.0'))->not->toHaveKey('comment_id')->not->toHaveKey('recipients');
    // The original (and any other note) stays reserved.
    expect(listedCommentContents($student))->not->toContain('Original reservado', 'Otra nota reservada');

    // Someone who was not a recipient sees no extract.
    Sanctum::actingAs($director);
    $this->getJson("/api/v1/students/{$student->id}/comment-shares")->assertOk()->assertJsonCount(0, 'data');

    // The extract text never lands in the audit trail.
    $log = AuditLog::withoutGlobalScopes()->where('auditable_type', CommentShare::class)->sole();
    expect($log->changes['content'])->toBe('[excluded]');
});

it('lets only psychopedagogy share (403 for anyone else, or for a note it cannot read)', function () {
    ['student' => $student, 'teacher' => $teacher, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $shared = Comment::factory()->forSubject($student)->create(['author_id' => $teacher->id]);
    $privateOfTeacher = Comment::factory()->forSubject($student)->authorOnly()->create(['author_id' => $teacher->id]);
    $payload = ['content' => 'Extracto', 'recipient_ids' => [$director->id]];

    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/comments/{$shared->id}/shares", $payload)->assertForbidden();
    Sanctum::actingAs($director);
    $this->postJson("/api/v1/comments/{$shared->id}/shares", ['content' => 'x', 'recipient_ids' => [$teacher->id]])->assertForbidden();

    Sanctum::actingAs($psico);
    $this->postJson("/api/v1/comments/{$privateOfTeacher->id}/shares", $payload)->assertForbidden();

    expect(CommentShare::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects recipients who cannot see the student (422)', function () {
    ['school' => $school, 'student' => $student, 'teacher' => $teacher, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $comment = Comment::factory()->forSubject($student)->create(['author_id' => $teacher->id]);
    $outsiderTeacher = User::factory()->forSchool($school)->teacher()->create();
    $otherPsico = User::factory()->forSchool($school)->psychopedagogue()->create();
    $foreignDirector = User::factory()->forSchool(School::factory()->create())->director()->create();

    Sanctum::actingAs($psico);
    foreach ([[$outsiderTeacher->id], [$otherPsico->id], [$foreignDirector->id], [$director->id, $outsiderTeacher->id], []] as $recipients) {
        $this->postJson("/api/v1/comments/{$comment->id}/shares", ['content' => 'Extracto', 'recipient_ids' => $recipients])
            ->assertUnprocessable();
    }
    expect(CommentShare::withoutGlobalScopes()->count())->toBe(0);
    $this->postJson("/api/v1/comments/{$comment->id}/shares", ['recipient_ids' => [$director->id]])
        ->assertJsonValidationErrors('content');

    // Direction and a teacher who teaches the student are valid together.
    $this->postJson("/api/v1/comments/{$comment->id}/shares", [
        'content' => 'Extracto', 'recipient_ids' => [$director->id, $teacher->id],
    ])->assertCreated();
});

it('isolates extracts and sharing per school', function () {
    ['student' => $student, 'teacher' => $teacher, 'psico' => $psico] = scopeFixture();
    $comment = Comment::factory()->forSubject($student)->create(['author_id' => $teacher->id]);
    $foreignPsico = User::factory()->forSchool(School::factory()->create())->psychopedagogue()->create();

    Sanctum::actingAs($psico);
    $this->postJson("/api/v1/comments/{$comment->id}/shares", ['content' => 'Extracto', 'recipient_ids' => [$teacher->id]])
        ->assertCreated();

    Sanctum::actingAs($foreignPsico);
    $this->postJson("/api/v1/comments/{$comment->id}/shares", ['content' => 'x', 'recipient_ids' => [$teacher->id]])
        ->assertNotFound();
    $this->getJson("/api/v1/students/{$student->id}/comment-shares")->assertNotFound();
});

it('does not count extracts toward the recipient\'s trend', function () {
    ['school' => $school, 'student' => $student, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $category = CommentCategory::withoutGlobalScopes()->where('school_id', $school->id)->value('id');
    $original = Comment::factory()->forSubject($student)->visibleTo(Comment::SCOPE_PSYCHOPEDAGOGY_ONLY)
        ->create(['author_id' => $psico->id]);
    $original->categories()->attach($category);

    Sanctum::actingAs($psico);
    foreach ([1, 2, 3] as $i) {
        $this->postJson("/api/v1/comments/{$original->id}/shares", ['content' => "Extracto {$i}", 'recipient_ids' => [$director->id]])
            ->assertCreated();
    }

    Sanctum::actingAs($director);
    expect(trackingFor($student)['comment_trends'])->toBe([]);
});

it('notifies each recipient of a shared extract and names the student only at read time', function () {
    ['student' => $student, 'teacher' => $teacher, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $original = Comment::factory()->forSubject($student)->visibleTo(Comment::SCOPE_PSYCHOPEDAGOGY_ONLY)
        ->create(['author_id' => $psico->id, 'content' => 'Original reservado']);

    Sanctum::actingAs($psico);
    $this->postJson("/api/v1/comments/{$original->id}/shares", [
        'content' => 'Extracto secreto',
        'recipient_ids' => [$teacher->id, $director->id],
    ])->assertCreated();

    foreach ([$teacher, $director] as $recipient) {
        $stored = $recipient->unreadNotifications()->sole();
        // The stored payload carries ids only: no extract, no student name.
        expect(json_encode($stored->data))->not->toContain('Extracto secreto')->not->toContain($student->full_name);

        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.data.type', 'comment_shared')
            ->assertJsonPath('data.0.data.student_id', $student->id)
            ->assertJsonPath('data.0.data.student_name', $student->full_name);
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);
    }

    // The author gets no notice of their own share.
    expect($psico->unreadNotifications()->count())->toBe(0);
});

it('names the students of shared-extract notices with a fixed number of queries', function () {
    ['student' => $student, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $original = Comment::factory()->forSubject($student)->visibleTo(Comment::SCOPE_PSYCHOPEDAGOGY_ONLY)
        ->create(['author_id' => $psico->id, 'content' => 'Original reservado']);
    $share = fn () => $this->postJson("/api/v1/comments/{$original->id}/shares", [
        'content' => 'Extracto', 'recipient_ids' => [$director->id],
    ])->assertCreated();
    $queries = function () use ($director): int {
        Sanctum::actingAs($director);
        DB::enableQueryLog();
        $this->getJson('/api/v1/notifications')->assertOk();
        DB::disableQueryLog();
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();

        return $count;
    };

    Sanctum::actingAs($psico);
    $share();
    $queries(); // warm-up: the first request also loads the viewer's roles
    $one = $queries();

    Sanctum::actingAs($psico);
    $share();
    $share();

    expect($queries())->toBe($one);
});

it('lets the author narrow each role of the scope to specific people («Elegir personas»)', function () {
    ['school' => $school, 'student' => $student, 'teacher' => $teacher, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $colleague = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($student->groups()->first(), $colleague);
    $otherDirector = User::factory()->forSchool($school)->director()->create();

    // «Todos» but, among teachers, only $colleague; directors and psico untouched.
    Sanctum::actingAs($teacher);
    $this->postJson("/api/v1/students/{$student->id}/comments", [
        'content' => 'Solo para un colega',
        'visible_to_users' => ['teacher' => [$colleague->id]],
    ])->assertCreated()->assertJsonPath('data.visible_to_users', ['teacher' => [$colleague->id]]);

    // «Dirección y Psicopedagogía» narrowed to one director; psico untouched.
    Sanctum::actingAs($psico);
    $this->postJson("/api/v1/students/{$student->id}/comments", [
        'content' => 'Para un director',
        'visible_to' => Comment::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY,
        'visible_to_users' => ['director' => [$director->id], 'psychopedagogue' => []],
    ])->assertCreated();

    $seen = function (User $viewer) use ($student): array {
        Sanctum::actingAs($viewer);

        return listedCommentContents($student);
    };

    expect($seen($teacher))->toContain('Solo para un colega')->not->toContain('Para un director')
        ->and($seen($colleague))->toContain('Solo para un colega')
        ->and($seen($director))->toContain('Solo para un colega', 'Para un director')
        ->and($seen($otherDirector))->toContain('Solo para un colega')->not->toContain('Para un director')
        ->and($seen($psico))->toContain('Solo para un colega', 'Para un director');

    // The cached tracking view applies the same rule (Comment::isVisibleTo).
    $outsider = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($student->groups()->first(), $outsider);
    Sanctum::actingAs($outsider);
    expect(collect(trackingFor($student)['recent_comments'])->pluck('content')->all())->not->toContain('Solo para un colega');
    // ...while the picked colleague does get it there (so the check above is not vacuous).
    Sanctum::actingAs($colleague);
    expect(collect(trackingFor($student)['recent_comments'])->pluck('content')->all())->toContain('Solo para un colega');
    Sanctum::actingAs($outsider);
    expect(listedCommentContents($student))->not->toContain('Solo para un colega');
});

it('rejects people outside the roles of the scope or who cannot see the student (422)', function () {
    ['school' => $school, 'student' => $student, 'teacher' => $teacher, 'psico' => $psico, 'director' => $director] = scopeFixture();
    $outsiderTeacher = User::factory()->forSchool($school)->teacher()->create();
    $foreignDirector = User::factory()->forSchool(School::factory()->create())->director()->create();

    Sanctum::actingAs($psico);
    foreach ([
        // A teacher can't be picked under «Dirección y Psicopedagogía».
        ['visible_to' => Comment::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY, 'visible_to_users' => ['teacher' => [$teacher->id]]],
        // Someone listed under a role they don't hold.
        ['visible_to_users' => ['director' => [$teacher->id]]],
        // A teacher who doesn't teach the student, a director from another school.
        ['visible_to_users' => ['teacher' => [$outsiderTeacher->id]]],
        ['visible_to_users' => ['director' => [$foreignDirector->id]]],
    ] as $payload) {
        $this->postJson("/api/v1/students/{$student->id}/comments", ['content' => 'x', ...$payload])
            ->assertJsonValidationErrors('visible_to_users');
    }

    // «Solo quien escribe» never stores people.
    $this->postJson("/api/v1/students/{$student->id}/comments", [
        'content' => 'Privada', 'author_only' => true, 'visible_to_users' => ['director' => [$director->id]],
    ])->assertCreated();
    expect(Comment::withoutGlobalScopes()->where('content', 'Privada')->value('visible_to_users'))->toBeNull();
});

it('lists extracts without one recipient query per row', function () {
    ['student' => $student, 'teacher' => $teacher, 'psico' => $psico] = scopeFixture();
    $comment = Comment::factory()->forSubject($student)->create(['author_id' => $teacher->id]);

    $queriesListing = function (int $newShares) use ($comment, $student, $teacher, $psico): int {
        Sanctum::actingAs($psico);
        foreach (range(1, $newShares) as $i) {
            test()->postJson("/api/v1/comments/{$comment->id}/shares", ['content' => "E{$i}", 'recipient_ids' => [$teacher->id]])
                ->assertCreated();
        }

        Sanctum::actingAs($teacher);
        // Warm-up: the first request also loads the caller's roles.
        test()->getJson("/api/v1/students/{$student->id}/comment-shares")->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson("/api/v1/students/{$student->id}/comment-shares")->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $withOne = $queriesListing(1);
    $withFour = $queriesListing(3);

    expect($withFour)->toBe($withOne);
});

it('stores nothing when notifying a recipient fails', function () {
    ['student' => $student, 'teacher' => $teacher, 'psico' => $psico] = scopeFixture();
    $comment = Comment::factory()->forSubject($student)->create(['author_id' => $teacher->id]);
    Event::listen(NotificationSending::class, fn () => throw new RuntimeException('notifications down'));

    Sanctum::actingAs($psico);
    $this->withoutExceptionHandling();
    expect(fn () => $this->postJson("/api/v1/comments/{$comment->id}/shares", [
        'content' => 'Extracto', 'recipient_ids' => [$teacher->id],
    ]))->toThrow(RuntimeException::class);

    expect(CommentShare::withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('comment_share_user')->count())->toBe(0);
});
