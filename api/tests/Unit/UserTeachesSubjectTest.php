<?php

use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The Unit suite is not bound to the Laravel TestCase in Pest.php (only Feature
// is), so bind it here — these model relations need a booted app + database.
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->group = Group::factory()->create();
    Tenancy::useSchool($this->group->school_id);
});

it('reports true only for an assigned (group, subject) pair', function () {
    $teacher = User::factory()->create(['school_id' => $this->group->school_id]);
    $math = Subject::factory()->for($this->group->school)->create(['name' => 'Matemática']);
    $english = Subject::factory()->for($this->group->school)->create(['name' => 'Inglés']);

    $this->group->teachers()->attach($teacher->id, ['subject_id' => $math->id]);

    expect($teacher->teachesSubjectInGroup($this->group, $math))->toBeTrue();
    expect($teacher->teachesSubjectInGroup($this->group, $english))->toBeFalse();
});

it('derives the distinct set of subjects a teacher teaches', function () {
    $teacher = User::factory()->create(['school_id' => $this->group->school_id]);
    $math = Subject::factory()->for($this->group->school)->create(['name' => 'Matemática']);
    $otherGroup = Group::factory()->for($this->group->school)->create();

    $this->group->teachers()->attach($teacher->id, ['subject_id' => $math->id]);
    $otherGroup->teachers()->attach($teacher->id, ['subject_id' => $math->id]);

    // Same subject in two groups → one distinct subject.
    expect($teacher->subjects()->pluck('subjects.id')->unique()->count())->toBe(1);
});
