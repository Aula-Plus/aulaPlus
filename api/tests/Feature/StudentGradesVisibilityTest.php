<?php

use App\Enums\GradesVisibility;
use App\Enums\Role;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RoleSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->group = Group::factory()->create();
    $this->school = $this->group->school;
    Tenancy::useSchool($this->school->id);

    $this->student = Student::factory()->for($this->school)->create();
    $this->group->students()->attach($this->student->id, ['school_year' => now()->year]);

    $this->math = Subject::factory()->for($this->school)->create(['name' => 'Matemática']);
    $this->history = Subject::factory()->for($this->school)->create(['name' => 'Historia']);

    $this->teacher = User::factory()->create(['school_id' => $this->school->id]);
    $this->teacher->assignRole(Role::Teacher->value);
    $this->group->teachers()->attach($this->teacher->id, ['subject_id' => $this->math->id]);

    $mathTest = Assessment::factory()->for($this->group)->create(['subject_id' => $this->math->id]);
    $this->historyTest = Assessment::factory()->for($this->group)->create(['subject_id' => $this->history->id]);
    AssessmentResult::factory()->for($mathTest)->for($this->student)->create(['score' => 8]);
    $this->historyResult = AssessmentResult::factory()->for($this->historyTest)->for($this->student)
        ->create(['score' => 3]);

    $this->url = "/api/v1/students/{$this->student->id}/tracking";
});

function subjectNames($response): array
{
    return collect($response->json('data.by_subject'))->pluck('subject_name')->all();
}

it('shows a teacher only their own subject by default', function () {
    actingAs($this->teacher);

    $response = getJson($this->url)->assertOk();

    expect(subjectNames($response))->toBe(['Matemática']);
    expect(collect($response->json('data.grades'))->pluck('subject_name')->all())->toBe(['Matemática']);
    // Without the other subjects the general average would leak them.
    $response->assertJsonPath('data.overall_average', 8);
    $response->assertJsonPath('data.grades_view.restricted', true);
});

it('also restricts the results and timeline endpoints', function () {
    actingAs($this->teacher);

    expect(getJson("/api/v1/students/{$this->student->id}/results")->assertOk()->json('data'))->toHaveCount(1);
    expect(getJson("/api/v1/students/{$this->student->id}/performance-timeline")->assertOk()->json('results'))
        ->toHaveCount(1);
});

it('lets psychopedagogy and direction see every subject', function (string $role) {
    $user = User::factory()->create(['school_id' => $this->school->id]);
    $user->assignRole($role);
    actingAs($user);

    $response = getJson($this->url)->assertOk();

    expect(subjectNames($response))->toBe(['Historia', 'Matemática']);
    $response->assertJsonPath('data.grades_view.restricted', false);
})->with([Role::Director->value, Role::Psychopedagogue->value]);

it('shows every subject live when direction allows it', function () {
    $this->school->update(['grades_visibility' => GradesVisibility::AllLive]);
    actingAs($this->teacher);

    expect(subjectNames(getJson($this->url)->assertOk()))->toBe(['Historia', 'Matemática']);
});

it('freezes other subjects at the last cutoff in periodic mode', function () {
    $this->school->update([
        'grades_visibility' => GradesVisibility::AllPeriodic,
        'grades_cutoff_months' => 3,
        'grades_cutoff_anchor' => now()->subMonths(4)->toDateString(), // last cutoff: 1 month ago
    ]);
    // Recorded before the cutoff → visible; recorded after → hidden until the next one.
    $this->historyResult->forceFill(['created_at' => now()->subMonths(2)])->save();
    $later = Assessment::factory()->for($this->group)->create(['subject_id' => $this->history->id]);
    AssessmentResult::factory()->for($later)->for($this->student)->create(['score' => 2]);
    actingAs($this->teacher);

    $response = getJson($this->url)->assertOk();

    $history = collect($response->json('data.by_subject'))->firstWhere('subject_name', 'Historia');
    expect((float) $history['average'])->toBe(3.0);
    expect($history['assessment_count'])->toBe(1);
    $response->assertJsonPath('data.grades_view.mode', 'all_periodic');
    $response->assertJsonPath('data.grades_view.cutoff_at', now()->subMonths(1)->toDateString());
});

it('only lets direction change the setting', function () {
    actingAs($this->teacher);
    putJson('/api/v1/grades-visibility', ['mode' => 'all_live'])->assertForbidden();

    $director = User::factory()->create(['school_id' => $this->school->id]);
    $director->assignRole(Role::Director->value);
    actingAs($director);

    putJson('/api/v1/grades-visibility', ['mode' => 'all_periodic'])->assertUnprocessable();
    putJson('/api/v1/grades-visibility', ['mode' => 'all_periodic', 'cutoff_months' => 3])
        ->assertOk()
        ->assertJsonPath('data.mode', 'all_periodic')
        ->assertJsonPath('data.cutoff_months', 3);
    expect($this->school->refresh()->grades_visibility)->toBe(GradesVisibility::AllPeriodic);

    getJson('/api/v1/grades-visibility')->assertOk()->assertJsonPath('data.mode', 'all_periodic');
});

it('does not touch another school when direction saves', function () {
    $other = School::factory()->create();
    $director = User::factory()->create(['school_id' => $this->school->id]);
    $director->assignRole(Role::Director->value);
    actingAs($director);

    putJson('/api/v1/grades-visibility', ['mode' => 'all_live'])->assertOk();

    expect($other->refresh()->grades_visibility)->toBe(GradesVisibility::OwnSubject);
});
