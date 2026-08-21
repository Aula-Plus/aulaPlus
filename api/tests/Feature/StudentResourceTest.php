<?php

use App\Http\Resources\StudentResource;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;

/**
 * These tests instantiate StudentResource directly (not through an HTTP
 * request) to exercise the field-level Gate check in toArray(). The global
 * `request()` singleton is only rebound with a working user resolver during a
 * real HTTP request/response cycle (see Illuminate\Auth\AuthServiceProvider),
 * so actingAs() alone isn't enough outside that cycle — the resolver is set
 * explicitly here to match what a real request would provide.
 */
function actingAsForResource(User $user): void
{
    test()->actingAs($user);
    request()->setUserResolver(fn () => $user);
}

it('serializes a student with its groups and clinical profile fields for a psychopedagogue', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => '3° A', 'school_year' => 2026]);
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'full_name' => 'Ana Gómez',
        'has_therapeutic_companion' => true,
        'tracking_notes' => 'Necesita seguimiento en lectoescritura.',
    ]);
    $student->groups()->attach($group, ['school_year' => 2026]);
    $student->load('groups');

    actingAsForResource($psychopedagogue);
    $array = (new StudentResource($student))->resolve(request());

    expect($array['full_name'])->toBe('Ana Gómez')
        ->and($array['has_therapeutic_companion'])->toBeTrue()
        ->and($array['groups'])->toBe([['id' => $group->id, 'name' => '3° A', 'school_year' => 2026]])
        ->and($array['tracking_notes'])->toBe('Necesita seguimiento en lectoescritura.');
});

it('serializes a student without groups as an empty list', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id])->load('groups');

    actingAsForResource($director);

    expect((new StudentResource($student))->toArray(request())['groups'])->toBe([]);
});

// Acceptance criterion (docs/prompts/02-roles-permisos.md §5): the sensitive
// field filtering in StudentResource must be verified by comparing the JSON
// keys present for a teacher without a teaching relation vs a psychopedagogue.
it('omits clinical profile keys for a teacher without a teaching relation, but includes them for a psychopedagogue', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'learning_profile' => ['reading_level' => 'B1'],
        'tracking_notes' => 'confidencial',
        'individual_profile' => ['note' => 'confidencial'],
        'related_documents' => ['doc.pdf'],
    ])->load('groups');

    actingAsForResource($teacher);
    $teacherKeys = array_keys((new StudentResource($student))->resolve(request()));

    actingAsForResource($psychopedagogue);
    $psychopedagogueKeys = array_keys((new StudentResource($student))->resolve(request()));

    $clinicalKeys = ['learning_profile', 'tracking_notes', 'individual_profile', 'related_documents'];

    foreach ($clinicalKeys as $key) {
        expect($teacherKeys)->not->toContain($key);
        expect($psychopedagogueKeys)->toContain($key);
    }
});
