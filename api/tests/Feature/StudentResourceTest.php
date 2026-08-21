<?php

use App\Http\Resources\StudentResource;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;

it('serializes a student with its groups and profile fields', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => '3° A', 'school_year' => 2026]);
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'full_name' => 'Ana Gómez',
        'has_therapeutic_companion' => true,
        'tracking_notes' => 'Necesita seguimiento en lectoescritura.',
    ]);
    $student->groups()->attach($group, ['school_year' => 2026]);
    $student->load('groups');

    $array = (new StudentResource($student))->toArray(request());

    expect($array['full_name'])->toBe('Ana Gómez')
        ->and($array['has_therapeutic_companion'])->toBeTrue()
        ->and($array['groups'])->toBe([['id' => $group->id, 'name' => '3° A', 'school_year' => 2026]])
        ->and($array['tracking_notes'])->toBe('Necesita seguimiento en lectoescritura.');
});

it('serializes a student without groups as an empty list', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id])->load('groups');

    expect((new StudentResource($student))->toArray(request())['groups'])->toBe([]);
});
