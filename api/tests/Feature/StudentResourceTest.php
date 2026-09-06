<?php

use App\Enums\StudentStatus;
use App\Http\Resources\StudentResource;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;

it('serializes a student with its group and profile fields', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => '3° A']);
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'group_id' => $group->id,
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
        'status' => StudentStatus::Active,
        'family_contact_name' => 'Marcela Gómez',
    ])->load('group');

    $array = (new StudentResource($student))->toArray(request());

    expect($array['full_name'])->toBe('Ana Gómez')
        ->and($array['status'])->toBe('active')
        ->and($array['group'])->toBe(['id' => $group->id, 'name' => '3° A'])
        ->and($array['family_contact_name'])->toBe('Marcela Gómez');
});

it('serializes a student without a group as null', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id, 'group_id' => null])->load('group');

    expect((new StudentResource($student))->toArray(request())['group'])->toBeNull();
});
