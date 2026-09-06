<?php

use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Models\School;
use App\Models\User;

it('serializes a group with its teacher', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create(['name' => 'Ana Pérez']);
    $group = Group::factory()->create([
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'name' => '3° A',
        'level' => 'Primaria',
        'year' => '2026',
    ])->load('teacher');

    $array = (new GroupResource($group))->toArray(request());

    expect($array)->toBe([
        'id' => $group->id,
        'name' => '3° A',
        'level' => 'Primaria',
        'year' => '2026',
        'teacher_id' => $teacher->id,
        'teacher' => ['id' => $teacher->id, 'name' => 'Ana Pérez'],
    ]);
});

it('serializes a group without a teacher as null', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => null])->load('teacher');

    expect((new GroupResource($group))->toArray(request())['teacher'])->toBeNull();
});
