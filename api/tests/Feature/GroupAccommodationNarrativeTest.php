<?php

use App\Enums\AccommodationNarrativeStatus;
use App\Models\Accommodation;
use App\Models\Group;
use App\Models\GroupAccommodationNarrative;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
    fakeNarrative('Lucía necesita tiempo extendido.');
    Sanctum::actingAs($psico);

    $this->postJson("/api/v1/groups/{$group->id}/accommodation-narrative/generate")->assertStatus(202);

    $draft = $this->getJson("/api/v1/groups/{$group->id}/accommodation-narrative")->json('draft');
    expect($draft['status'])->toBe('error')->and($draft['content'])->toBeNull();
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
        ->and($draft['content'])->toBeNull();
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
