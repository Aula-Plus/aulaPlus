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

it('returns per-subject averages and an overall average', function () {
    $math = Subject::factory()->for($this->group->school)->create(['name' => 'Matemática']);
    $english = Subject::factory()->for($this->group->school)->create(['name' => 'Inglés']);

    // Two Matemática assessments (not two results on one) — a single
    // (assessment_id, student_id) unique index forbids two results per
    // assessment for the same student, so the two scored Matemática
    // assessments give avg 7 over count 2.
    $mathA = Assessment::factory()->for($this->group)->create(['subject_id' => $math->id]);
    $mathB = Assessment::factory()->for($this->group)->create(['subject_id' => $math->id]);
    $engA = Assessment::factory()->for($this->group)->create(['subject_id' => $english->id]);

    AssessmentResult::factory()->for($mathA)->for($this->student)->create(['score' => 8]);
    AssessmentResult::factory()->for($mathB)->for($this->student)->create(['score' => 6]); // Matemática → avg 7
    AssessmentResult::factory()->for($engA)->for($this->student)->create(['score' => 10]);

    actingAs($this->director);

    $response = getJson("/api/v1/students/{$this->student->id}/tracking")->assertOk();

    // Overall average = mean(8, 6, 10) = 8
    $response->assertJsonPath('data.overall_average', 8);

    $bySubject = collect($response->json('data.by_subject'))->keyBy('subject_name');
    expect((float) $bySubject['Matemática']['average'])->toBe(7.0);
    expect($bySubject['Matemática']['assessment_count'])->toBe(2);
    expect((float) $bySubject['Inglés']['average'])->toBe(10.0);
});
