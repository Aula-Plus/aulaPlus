<?php

use App\Enums\AccommodationNarrativeStatus;
use App\Http\Controllers\GroupAccommodationNarrativeController;
use App\Jobs\GenerateGroupAccommodationNarrativeJob;
use App\Models\Accommodation;
use App\Models\Group;
use App\Models\GroupAccommodationNarrative;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;

function narrativeSetup(): array
{
    $school = School::factory()->create();
    $psico = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);
    $student = Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Lucía Pereyra']);
    $student->groups()->attach($group, ['school_year' => now()->year]);
    Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'tiempo extendido', 'category' => 'access', 'active' => true]);

    return [$school, $psico, $group, $student];
}

function fakeNarrative(string $text): void
{
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $text]]], 200)]);
}

function addStudentToGroup(Group $group, string $fullName): Student
{
    $student = Student::factory()->create(['school_id' => $group->school_id, 'full_name' => $fullName]);
    $student->groups()->attach($group, ['school_year' => now()->year]);

    return $student;
}

function pendingNarrative(Group $group, User $by): GroupAccommodationNarrative
{
    return GroupAccommodationNarrative::create([
        'group_id' => $group->id,
        'status' => AccommodationNarrativeStatus::Pending,
        'fingerprint' => str_repeat('0', 64),
        'generated_by_id' => $by->id,
    ]);
}

it('lets psychopedagogy generate a draft and sends no student name to the AI', function () {
    [, $psico, $group] = narrativeSetup();
    fakeNarrative('En este grupo, 1 alumno tiene tiempo extendido.');
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);

    Http::assertSent(function (Request $request) {
        $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($body, 'tiempo extendido, access, 1 alumnos')
            && ! str_contains($body, 'Pereyra')
            && ! str_contains($body, 'Lucía');
    });

    $draft = $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertOk()->json('draft');
    expect($draft['status'])->toBe('draft')
        ->and($draft['content'])->toContain('tiempo extendido')
        ->and($draft['outdated'])->toBeFalse();
});

it('discards an AI text that names a student', function () {
    [, $psico, $group] = narrativeSetup();
    fakeNarrative('Lucía Pereyra necesita tiempo extendido.');
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);

    $draft = $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->json('draft');
    expect($draft['status'])->toBe('error')->and($draft['content'])->toBeNull();
});

it('discards an AI text that names a student without the accent, surname first', function () {
    [, $psico, $group] = narrativeSetup();
    fakeNarrative('Pereyra, Lucia necesita tiempo extendido.');
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);

    $draft = $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->json('draft');
    expect($draft['status'])->toBe('error')->and($draft['content'])->toBeNull();
});

it('accepts an AI text where a student name is just a common word', function () {
    [$school, $psico, $group] = narrativeSetup();
    addStudentToGroup($group, 'Clara Luz Franco');
    // Students of other groups, with ordinary words as names, too.
    Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Pilar Blanco León']);
    fakeNarrative('Lucía: presentá las consignas de forma clara, con buena luz, y dá tiempo extendido. Más apoyo en el pilar de la lectura.');
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);

    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertJsonPath('draft.status', 'draft');
});

it('discards an AI text with the full name of a student of another group', function () {
    [$school, $psico, $group] = narrativeSetup();
    Student::factory()->create(['school_id' => $school->id, 'full_name' => 'Mateo Pérez-Gómez']);
    fakeNarrative('Por ejemplo, Mateo Pérez necesita tiempo extendido.');
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);

    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertJsonPath('draft.status', 'error');
});

it('sends a group named like a student (San José) and keeps its name out when it is a full name', function () {
    [$school, $psico, $group] = narrativeSetup();
    addStudentToGroup($group, 'José Rodríguez');
    $group->update(['name' => 'San José']);
    fakeNarrative('Resumen.');
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);
    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertJsonPath('draft.status', 'draft');
    Http::assertSent(fn (Request $request) => str_contains($request['messages'][0]['content'], 'Grupo: San José'));

    $group->update(['name' => 'Grupo de Lucía Pereyra']);
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);
    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertJsonPath('draft.status', 'draft');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'Pereyra'));
});

it('does not start a second generation while one is running, but replaces a stale one', function () {
    [, $psico, $group] = narrativeSetup();
    fakeNarrative('Resumen.');
    Sanctum::actingAs($psico);

    $running = pendingNarrative($group, $psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")
        ->assertStatus(202)
        ->assertJsonPath('id', $running->id);
    Http::assertNothingSent();
    expect(GroupAccommodationNarrative::where('group_id', $group->id)->count())->toBe(1);

    $running->forceFill(['created_at' => now()->subHour()])->save();

    $id = $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202)->json('id');
    expect($id)->not->toBe($running->id)
        ->and(GroupAccommodationNarrative::find($id)->status)->toBe(AccommodationNarrativeStatus::Draft)
        ->and($running->refresh()->status)->toBe(AccommodationNarrativeStatus::Error);

    // If a worker picks the given-up job late, it does nothing.
    GenerateGroupAccommodationNarrativeJob::dispatchSync($running);
    Http::assertSentCount(1);
    expect($running->refresh()->status)->toBe(AccommodationNarrativeStatus::Error);
});

it('reports a pending generation that never finished as failed, so it can be generated again', function () {
    [, $psico, $group] = narrativeSetup();
    Sanctum::actingAs($psico);
    $lost = pendingNarrative($group, $psico);

    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")
        ->assertJsonPath('draft.status', 'pending')
        ->assertJsonPath('draft.stale', false);

    $this->travel(GroupAccommodationNarrativeController::PENDING_WINDOW_MINUTES + 1)->minutes();

    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")
        ->assertJsonPath('draft.id', $lost->id)
        ->assertJsonPath('draft.status', 'error')
        ->assertJsonPath('draft.stale', true)
        ->assertJsonPath('draft.name_in_data', false);
});

it('starts only one generation when two requests race past the check', function () {
    [, $psico, $group] = narrativeSetup();
    Http::fake();
    Sanctum::actingAs($psico);

    // The other request inserts its pending row right after our check (it
    // makes our insert fail) and commits it (it survives our rollback).
    $row = [
        'school_id' => $group->school_id,
        'group_id' => $group->id,
        'status' => AccommodationNarrativeStatus::Pending->value,
        'fingerprint' => str_repeat('0', 64),
        'generated_by_id' => $psico->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $competitor = null;
    GroupAccommodationNarrative::creating(function () use (&$competitor, $row) {
        $competitor ??= DB::table('group_accommodation_narratives')->insertGetId($row);
    });
    Event::listen(TransactionRolledBack::class, function () use (&$competitor, $row) {
        if (DB::table('group_accommodation_narratives')->where('id', $competitor)->doesntExist()) {
            DB::table('group_accommodation_narratives')->insert(['id' => $competitor] + $row);
        }
    });

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")
        ->assertStatus(202)
        ->assertJsonPath('id', $competitor);

    Http::assertNothingSent();
    expect(GroupAccommodationNarrative::where('group_id', $group->id)->count())->toBe(1);
});

it('never stores two pending generations for the same group', function () {
    [, $psico, $group] = narrativeSetup();
    Sanctum::actingAs($psico);
    pendingNarrative($group, $psico);

    expect(fn () => pendingNarrative($group, $psico))->toThrow(UniqueConstraintViolationException::class);
});

it('stores the fingerprint of the data actually summarised and refuses an emptied group at run time', function () {
    [$school, $psico, $group, $student] = narrativeSetup();
    fakeNarrative('Resumen.');
    Sanctum::actingAs($psico);

    $narrative = GroupAccommodationNarrative::create([
        'group_id' => $group->id,
        'status' => AccommodationNarrativeStatus::Pending,
        'fingerprint' => str_repeat('0', 64),
        'generated_by_id' => $psico->id,
    ]);
    GenerateGroupAccommodationNarrativeJob::dispatchSync($narrative);

    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")
        ->assertJsonPath('draft.status', 'draft')
        ->assertJsonPath('draft.outdated', false);

    // Accommodations ended between the request and the job: nothing is sent.
    Accommodation::query()->where('student_id', $student->id)->update(['active' => false]);
    $emptied = GroupAccommodationNarrative::create([
        'group_id' => $group->id,
        'status' => AccommodationNarrativeStatus::Pending,
        'fingerprint' => str_repeat('0', 64),
        'generated_by_id' => $psico->id,
    ]);
    GenerateGroupAccommodationNarrativeJob::dispatchSync($emptied);

    Http::assertSentCount(1); // only the first job reached the AI
    expect($emptied->refresh()->status)->toBe(AccommodationNarrativeStatus::Error);
});

it('does not publish a narrative of another group or another school', function () {
    [$school, $psico, $group] = narrativeSetup();
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psico);
    $sameSchool = GroupAccommodationNarrative::create([
        'group_id' => $otherGroup->id,
        'status' => AccommodationNarrativeStatus::Draft,
        'content' => 'Otro grupo.',
        'fingerprint' => str_repeat('0', 64),
        'generated_by_id' => $psico->id,
    ]);

    [, $foreignPsico, $foreignGroup] = narrativeSetup();
    Sanctum::actingAs($foreignPsico);
    $foreign = GroupAccommodationNarrative::create([
        'group_id' => $foreignGroup->id,
        'status' => AccommodationNarrativeStatus::Draft,
        'content' => 'Otra escuela.',
        'fingerprint' => str_repeat('0', 64),
        'generated_by_id' => $foreignPsico->id,
    ]);

    Sanctum::actingAs($psico);
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/{$sameSchool->id}/publish")->assertNotFound();
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/{$foreign->id}/publish")->assertNotFound();

    expect(GroupAccommodationNarrative::withoutGlobalScopes()->whereIn('id', [$sameSchool->id, $foreign->id])->pluck('status')->all())
        ->each->toBe(AccommodationNarrativeStatus::Draft);
});

it('sends nothing to the AI when an accommodation text names a student', function () {
    [, $psico, $group, $student] = narrativeSetup();
    Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'lectura en voz alta para Lucía', 'category' => 'access', 'active' => true]);
    Http::fake();
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);

    Http::assertNothingSent();
    $draft = $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->json('draft');
    expect($draft['status'])->toBe('error')
        ->and($draft['name_in_data'])->toBeTrue()
        // Says which accommodation to fix, without repeating the name.
        ->and($draft['flagged_types'])->toBe(['lectura en voz alta para …'])
        ->and($draft['content'])->toBeNull();
});

it('blocks a single name part of a student of the group in the data, even inside a hyphenated word', function () {
    [, $psico, $group] = narrativeSetup();
    addStudentToGroup($group, 'Mateo Pérez-Gómez');
    Accommodation::factory()->create(['student_id' => addStudentToGroup($group, 'Ana Ruiz')->id, 'type' => 'sentar junto a gomez,', 'category' => 'access', 'active' => true]);
    Http::fake();
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);

    Http::assertNothingSent();
    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")
        ->assertJsonPath('draft.name_in_data', true)
        ->assertJsonPath('draft.flagged_types', ['sentar junto a …,']);
});

it('refuses to generate when the group has no active accommodations', function () {
    [$school, $psico] = narrativeSetup();
    $empty = Group::factory()->create(['school_id' => $school->id]);
    Http::fake();
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$empty->id}/accommodation-narrative/generate")->assertUnprocessable();
    Http::assertNothingSent();
});

it('only publishes a draft, replaces the previous published one and hides drafts from teachers', function () {
    [$school, $psico, $group] = narrativeSetup();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    leadGroup($group, $teacher);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['content' => [['type' => 'text', 'text' => 'Primer resumen.']]])
        ->push(['content' => [['type' => 'text', 'text' => 'Segundo resumen.']]])]);
    Sanctum::actingAs($psico);

    $first = $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->json('id');

    // Teacher sees nothing until it is published.
    Sanctum::actingAs($teacher);
    $view = $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertOk();
    expect($view->json('published'))->toBeNull()->and($view->json('draft'))->toBeNull()->and($view->json('can_generate'))->toBeFalse();
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/{$first}/publish")->assertForbidden();

    Sanctum::actingAs($psico);
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/{$first}/publish")->assertOk();
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/{$first}/publish")->assertUnprocessable();

    $second = $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->json('id');
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/{$second}/publish")->assertOk();

    expect(GroupAccommodationNarrative::find($first)->status)->toBe(AccommodationNarrativeStatus::Archived);

    Sanctum::actingAs($teacher);
    $published = $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->json('published');
    expect($published['content'])->toBe('Segundo resumen.')->and($published['outdated'])->toBeFalse();
});

it('archives older drafts when a new one is generated, so they can no longer be published', function () {
    [, $psico, $group] = narrativeSetup();
    fakeNarrative('Resumen.');
    Sanctum::actingAs($psico);

    $old = $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->json('id');
    $new = $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->json('id');

    expect(GroupAccommodationNarrative::find($old)->status)->toBe(AccommodationNarrativeStatus::Archived);
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/{$old}/publish")->assertUnprocessable();
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/{$new}/publish")->assertOk();

    expect(GroupAccommodationNarrative::where('group_id', $group->id)->where('status', AccommodationNarrativeStatus::Published)->count())->toBe(1);
});

it('never leaves a narrative pending when the job fails', function () {
    [, $psico, $group] = narrativeSetup();
    Sanctum::actingAs($psico);
    $narrative = pendingNarrative($group, $psico);

    (new GenerateGroupAccommodationNarrativeJob($narrative))->failed(new RuntimeException('worker died'));

    expect($narrative->refresh()->status)->toBe(AccommodationNarrativeStatus::Error);

    // A narrative that already finished is left alone.
    $narrative->update(['status' => AccommodationNarrativeStatus::Draft, 'content' => 'Resumen.']);
    (new GenerateGroupAccommodationNarrativeJob($narrative))->failed(null);
    expect($narrative->refresh()->status)->toBe(AccommodationNarrativeStatus::Draft);
});

it('ends in error when the AI provider fails', function (int $status) {
    [, $psico, $group] = narrativeSetup();
    Sanctum::actingAs($psico);
    Sleep::fake();
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'x']], $status)]);
    $narrative = pendingNarrative($group, $psico);

    GenerateGroupAccommodationNarrativeJob::dispatchSync($narrative);

    Http::assertSentCount(2); // retried once by the HTTP client, never by the queue
    expect($narrative->refresh()->status)->toBe(AccommodationNarrativeStatus::Error)
        ->and($narrative->error_message)->toBe("AI provider returned HTTP {$status}.");
})->with([500, 429]);

it('is never retried by the queue and times out before the queue hands it to another worker', function () {
    [, $psico, $group] = narrativeSetup();
    Sanctum::actingAs($psico);
    $job = new GenerateGroupAccommodationNarrativeJob(pendingNarrative($group, $psico));

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'));
});

it('flags the published text as outdated once the accommodations change', function () {
    [, $psico, $group, $student] = narrativeSetup();
    fakeNarrative('Resumen.');
    Sanctum::actingAs($psico);
    $id = $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->json('id');
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/{$id}/publish")->assertOk();

    Accommodation::factory()->create(['student_id' => $student->id, 'type' => 'consignas leídas', 'category' => 'access', 'active' => true]);

    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertJsonPath('published.outdated', true);
});

it('forbids a director and a teacher from generating, and non-leading teachers from reading and other schools from reaching it', function () {
    [$school, , $group] = narrativeSetup();
    $director = User::factory()->forSchool($school)->director()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $outsider = User::factory()->forSchool(School::factory()->create())->psychopedagogue()->create();
    Http::fake();

    foreach ([$director, $teacher] as $user) {
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertForbidden();
    }

    // Another school never even sees the group (tenant scope → 404).
    Sanctum::actingAs($outsider);
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertNotFound();

    Sanctum::actingAs($teacher);
    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertForbidden();
    Sanctum::actingAs($director);
    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertOk();
});

it('requires authentication', function () {
    [, , $group] = narrativeSetup();
    $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->assertUnauthorized();
    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertUnauthorized();
});
