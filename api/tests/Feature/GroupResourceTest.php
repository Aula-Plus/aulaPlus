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
    leadGroup($group, $teacher);
    $group->load('teachers');

    // resolve() applies the conditional-field filtering (whenLoaded, when),
    // giving the final serialized shape a client actually receives.
    $array = (new GroupResource($group))->resolve(request());

    // active_tracking_count is only present when the controller computed it
    // for the listing; a bare resource omits it rather than emitting a 0.
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

    expect((new GroupResource($group))->resolve(request())['teachers'])->toBe([]);
});

it('includes active_tracking_count (as int) when it has been set on the group', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id])->load('teachers');
    // Mirrors what GroupController::index sets before serializing.
    $group->active_tracking_count = 3;

    $array = (new GroupResource($group))->resolve(request());

    expect($array)->toHaveKey('active_tracking_count')
        ->and($array['active_tracking_count'])->toBe(3);
});
