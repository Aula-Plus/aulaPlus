<?php

use App\Models\Group;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy;
use Laravel\Sanctum\Sanctum;

afterEach(fn () => Tenancy::forget());

it('scopes queries to the active school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    Group::factory()->count(2)->create(['school_id' => $schoolA->id]);
    Group::factory()->count(3)->create(['school_id' => $schoolB->id]);

    Tenancy::useSchool($schoolA);
    expect(Group::count())->toBe(2);

    Tenancy::useSchool($schoolB);
    expect(Group::count())->toBe(3);
});

it('auto-fills school_id on create from the current tenant', function () {
    $school = School::factory()->create();

    $group = Tenancy::forSchool($school, fn () => Group::create(['name' => '3° A']));

    expect($group->school_id)->toBe($school->id);
});

it('derives the tenant from the authenticated user', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $groupA = Group::factory()->create(['school_id' => $schoolA->id]);
    Group::factory()->create(['school_id' => $schoolB->id]);

    $userA = User::factory()->forSchool($schoolA)->teacher()->create();

    $this->actingAs($userA);

    // Without ever writing a where clause, only school A's rows are visible.
    expect(Group::pluck('id')->all())->toBe([$groupA->id]);
});

it('scopes queries for a token-authenticated user (sanctum guard)', function () {
    // Regression: Tenancy must resolve the tenant across guards. A token client
    // authenticates on the "sanctum" guard (not "web"); if the scope only read
    // the default guard it would fail open and leak every school's rows.
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $groupA = Group::factory()->create(['school_id' => $schoolA->id]);
    Group::factory()->create(['school_id' => $schoolB->id]);

    Sanctum::actingAs(User::factory()->forSchool($schoolA)->create());

    expect(Group::pluck('id')->all())->toBe([$groupA->id]);
});

it('cannot read another school\'s record even by id', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);

    $this->actingAs(User::factory()->forSchool($schoolA)->create());

    expect(Group::find($groupB->id))->toBeNull();
});
