<?php

use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Models\School;
use App\Models\User;

it('serializes a group with its teachers', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create(['name' => 'Ana Pérez']);
    $group = Group::factory()->create([
        'school_id' => $school->id,
        'name' => '3° A',
        'level' => 'Primaria',
        'school_year' => 2026,
    ]);
    $group->teachers()->attach($teacher);
    $group->load('teachers');

    $array = (new GroupResource($group))->toArray(request());

    expect($array)->toBe([
        'id' => $group->id,
        'name' => '3° A',
        'level' => 'Primaria',
        'school_year' => 2026,
        'group_profile' => null,
        'related_documents' => null,
        'teachers' => [['id' => $teacher->id, 'name' => 'Ana Pérez']],
    ]);
});

it('serializes a group without teachers as an empty list', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id])->load('teachers');

    expect((new GroupResource($group))->toArray(request())['teachers'])->toBe([]);
});
