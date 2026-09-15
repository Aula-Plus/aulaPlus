<?php

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RoleSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->group = Group::factory()->create();
    Tenancy::useSchool($this->group->school_id);
    $this->student = Student::factory()->for($this->group->school)->create();
    $this->group->students()->attach($this->student->id, ['school_year' => now()->year]);
    $this->director = User::factory()->create(['school_id' => $this->group->school_id]);
    $this->director->assignRole(Role::Director->value);
});

it('filters the results line by subject_id', function () {
    $math = Subject::factory()->for($this->group->school)->create(['name' => 'Matemática']);
    $english = Subject::factory()->for($this->group->school)->create(['name' => 'Inglés']);

    $mathA = Assessment::factory()->for($this->group)->create(['subject_id' => $math->id]);
    $engA = Assessment::factory()->for($this->group)->create(['subject_id' => $english->id]);
    AssessmentResult::factory()->for($mathA)->for($this->student)->create(['score' => 7]);
    AssessmentResult::factory()->for($engA)->for($this->student)->create(['score' => 9]);

    actingAs($this->director);

    $response = getJson("/api/v1/students/{$this->student->id}/performance-timeline?subject_id={$math->id}")
        ->assertOk();

    // Only the Matemática result is on the line.
    expect($response->json('results'))->toHaveCount(1);
});

it('returns all results when no subject_id is given', function () {
    $math = Subject::factory()->for($this->group->school)->create();
    $mathA = Assessment::factory()->for($this->group)->create(['subject_id' => $math->id]);
    AssessmentResult::factory()->for($mathA)->for($this->student)->create(['score' => 7]);

    actingAs($this->director);

    getJson("/api/v1/students/{$this->student->id}/performance-timeline")
        ->assertOk()
        ->assertJsonCount(1, 'results');
});
