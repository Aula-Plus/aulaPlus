<?php

use App\Enums\GradesVisibility;
use App\Enums\Role;
use App\Http\Controllers\StudentTrackingController;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentGradeAccess;
use App\Support\SchoolTime;
use App\Support\Tenancy;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

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

it('shows other subjects only as loaded before the last cutoff in periodic mode', function (string $today) {
    // Frozen clock, incl. month-end dates where naive month arithmetic overflows
    // (e.g. 31/07 − 4 months + 3 months ≠ 31/07 − 1 month).
    $this->travelTo(Carbon::parse($today.' 12:00', SchoolTime::timezone()));
    $anchor = SchoolTime::today()->subMonthsNoOverflow(4);
    $expectedCutoff = $anchor->addMonthsNoOverflow(3); // anchor + 1 × 3 months
    $this->school->update([
        'grades_visibility' => GradesVisibility::AllPeriodic,
        'grades_cutoff_months' => 3,
        'grades_cutoff_anchor' => $anchor->toDateString(),
    ]);
    // Loaded before the cutoff → visible; loaded after → hidden until the next one.
    $this->historyResult->forceFill(['created_at' => $expectedCutoff->subMonth()])->save();
    $later = Assessment::factory()->for($this->group)->create(['subject_id' => $this->history->id]);
    AssessmentResult::factory()->for($later)->for($this->student)->create(['score' => 2]);
    actingAs($this->teacher);

    $response = getJson($this->url)->assertOk();

    $history = collect($response->json('data.by_subject'))->firstWhere('subject_name', 'Historia');
    expect((float) $history['average'])->toBe(3.0);
    expect($history['assessment_count'])->toBe(1);
    $response->assertJsonPath('data.grades_view.mode', 'all_periodic');
    $response->assertJsonPath('data.grades_view.cutoff_at', $expectedCutoff->toDateString());
})->with(['2026-07-31', '2026-05-31', '2026-03-29', '2026-03-31', '2026-10-10']);

it('hides other-subject grades loaded on the cutoff day itself (school time)', function () {
    // Cutoff on 2026-07-01 (anchor 2026-04-01 + 3 months); now 2026-07-02 local.
    $this->travelTo(Carbon::parse('2026-07-02 10:00', SchoolTime::timezone()));
    $this->school->update([
        'grades_visibility' => GradesVisibility::AllPeriodic,
        'grades_cutoff_months' => 3,
        'grades_cutoff_anchor' => '2026-04-01',
    ]);
    // 30/06 23:30 in Montevideo = 01/07 02:30 UTC: before the local cutoff day.
    $this->historyResult->forceFill([
        'created_at' => Carbon::parse('2026-06-30 23:30', SchoolTime::timezone())->utc(),
    ])->save();
    // 01/07 00:30 in Montevideo: on the cutoff day → not visible yet.
    $onCutoffDay = Assessment::factory()->for($this->group)->create(['subject_id' => $this->history->id]);
    AssessmentResult::factory()->for($onCutoffDay)->for($this->student)->create([
        'score' => 2,
        'created_at' => Carbon::parse('2026-07-01 00:30', SchoolTime::timezone())->utc(),
    ]);
    actingAs($this->teacher);

    $response = getJson($this->url)->assertOk();

    $history = collect($response->json('data.by_subject'))->firstWhere('subject_name', 'Historia');
    expect($history['assessment_count'])->toBe(1);
    expect((float) $history['average'])->toBe(3.0);
    $response->assertJsonPath('data.grades_view.cutoff_at', '2026-07-01');
});

it('counts "today" in the school timezone, not UTC', function () {
    $school = School::factory()->create([
        'grades_visibility' => GradesVisibility::AllPeriodic,
        'grades_cutoff_months' => 1,
        'grades_cutoff_anchor' => '2026-06-01',
    ]);
    $access = app(StudentGradeAccess::class);

    // 31/07 22:00 in Montevideo is already 01/08 in UTC: the cutoff of 01/08
    // hasn't happened yet locally.
    $this->travelTo(Carbon::parse('2026-07-31 22:00', SchoolTime::timezone()));
    expect($access->lastCutoff($school)->toDateString())->toBe('2026-07-01');

    $this->travelTo(Carbon::parse('2026-08-01 00:05', SchoolTime::timezone()));
    expect($access->lastCutoff($school)->toDateString())->toBe('2026-08-01');
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

it('shows no grades on any endpoint to a teacher of the student whose subject has none', function () {
    $art = Subject::factory()->for($this->school)->create(['name' => 'Arte']);
    $teacher = User::factory()->create(['school_id' => $this->school->id]);
    $teacher->assignRole(Role::Teacher->value);
    $this->group->teachers()->attach($teacher->id, ['subject_id' => $art->id]);
    actingAs($teacher);

    $response = getJson($this->url)->assertOk();

    expect($response->json('data.by_subject'))->toBe([]);
    expect($response->json('data.grades'))->toBe([]);
    $response->assertJsonPath('data.overall_average', null);
    expect(getJson("/api/v1/students/{$this->student->id}/results")->assertOk()->json('data'))->toBe([]);
    expect(getJson("/api/v1/students/{$this->student->id}/performance-timeline")->assertOk()->json('results'))
        ->toBe([]);
});

it('does not open a subject the teacher only teaches in another group', function () {
    $otherGroup = Group::factory()->create(['school_id' => $this->school->id]);
    $otherGroup->teachers()->attach($this->teacher->id, ['subject_id' => $this->history->id]);
    actingAs($this->teacher);

    expect(subjectNames(getJson($this->url)->assertOk()))->toBe(['Matemática']);
    expect(getJson("/api/v1/students/{$this->student->id}/results")->assertOk()->json('data'))->toHaveCount(1);
});

it('applies the periodic cutoff on the results and timeline endpoints too', function () {
    $this->school->update([
        'grades_visibility' => GradesVisibility::AllPeriodic,
        'grades_cutoff_months' => 3,
        'grades_cutoff_anchor' => SchoolTime::today()->subMonthsNoOverflow(4)->toDateString(),
    ]);
    // The History result was recorded after the last cutoff → hidden for now.
    actingAs($this->teacher);

    expect(getJson("/api/v1/students/{$this->student->id}/results")->assertOk()->json('data'))->toHaveCount(1);
    expect(getJson("/api/v1/students/{$this->student->id}/performance-timeline")->assertOk()->json('results'))
        ->toHaveCount(1);
});

it('forbids psychopedagogy from changing the setting and validates the mode', function () {
    $psychopedagogue = User::factory()->create(['school_id' => $this->school->id]);
    $psychopedagogue->assignRole(Role::Psychopedagogue->value);
    actingAs($psychopedagogue);
    putJson('/api/v1/grades-visibility', ['mode' => 'all_live'])->assertForbidden();
    expect($this->school->refresh()->grades_visibility)->toBe(GradesVisibility::OwnSubject);

    $director = User::factory()->create(['school_id' => $this->school->id]);
    $director->assignRole(Role::Director->value);
    actingAs($director);
    putJson('/api/v1/grades-visibility', ['mode' => 'everything'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mode']);
    putJson('/api/v1/grades-visibility', ['mode' => 'all_periodic', 'cutoff_months' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cutoff_months']);
});

it('computes every cutoff from the anchor without month-end drift', function () {
    $school = School::factory()->create([
        'grades_visibility' => GradesVisibility::AllPeriodic,
        'grades_cutoff_months' => 1,
        'grades_cutoff_anchor' => '2026-01-31',
    ]);
    $access = app(StudentGradeAccess::class);

    expect($access->lastCutoff($school, Carbon::parse('2026-01-30')))->toBeNull();
    expect($access->lastCutoff($school, Carbon::parse('2026-03-01'))->toDateString())->toBe('2026-02-28');
    expect($access->lastCutoff($school, Carbon::parse('2026-04-15'))->toDateString())->toBe('2026-03-31');
});

it('finds the last cutoff arithmetically, even from a very old anchor', function () {
    $school = School::factory()->create([
        'grades_visibility' => GradesVisibility::AllPeriodic,
        'grades_cutoff_months' => 1,
        'grades_cutoff_anchor' => '0001-01-31',
    ]);
    $access = app(StudentGradeAccess::class);

    expect($access->lastCutoff($school, Carbon::parse('2026-03-15'))->toDateString())->toBe('2026-02-28');
    expect($access->lastCutoff($school, Carbon::parse('2026-03-31'))->toDateString())->toBe('2026-03-31');

    $school->grades_cutoff_months = 5;
    $school->grades_cutoff_anchor = '2025-10-31';
    // 2025-10-31 + 5 → 2026-03-31 (not 03-28 by stepping), + 10 → 2026-08-31.
    expect($access->lastCutoff($school, Carbon::parse('2026-03-30'))->toDateString())->toBe('2025-10-31');
    expect($access->lastCutoff($school, Carbon::parse('2026-03-31'))->toDateString())->toBe('2026-03-31');
    expect($access->lastCutoff($school, Carbon::parse('2026-08-31'))->toDateString())->toBe('2026-08-31');
});

it('rejects a cutoff anchor outside a sane range', function (string $anchor) {
    $director = User::factory()->create(['school_id' => $this->school->id]);
    $director->assignRole(Role::Director->value);
    actingAs($director);

    putJson('/api/v1/grades-visibility', ['mode' => 'all_periodic', 'cutoff_months' => 3, 'cutoff_anchor' => $anchor])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cutoff_anchor']);
})->with([
    'too old' => '0001-01-01',
    'in the future' => fn () => SchoolTime::today()->addDay()->toDateString(),
    'not a date' => '31/07/2026',
]);

it('keeps the periodic cutoff settings when direction switches modes and back', function () {
    $director = User::factory()->create(['school_id' => $this->school->id]);
    $director->assignRole(Role::Director->value);
    actingAs($director);

    putJson('/api/v1/grades-visibility', ['mode' => 'all_periodic', 'cutoff_months' => 4, 'cutoff_anchor' => '2026-03-01'])
        ->assertOk();
    putJson('/api/v1/grades-visibility', ['mode' => 'own_subject'])
        ->assertOk()
        ->assertJsonPath('data.cutoff_anchor', '2026-03-01')
        ->assertJsonPath('data.cutoff_months', 4)
        ->assertJsonPath('data.last_cutoff_at', null);

    putJson('/api/v1/grades-visibility', ['mode' => 'all_periodic', 'cutoff_months' => 4])
        ->assertOk()
        ->assertJsonPath('data.cutoff_anchor', '2026-03-01');
});

it('flags the grades list as truncated past the limit while averages use every grade', function () {
    $limit = StudentTrackingController::GRADES_LIMIT;
    $assessments = Assessment::factory()->for($this->group)->count($limit)->create(['subject_id' => $this->math->id]);
    foreach ($assessments as $assessment) {
        AssessmentResult::factory()->for($assessment)->for($this->student)->create(['score' => 8]);
    }
    actingAs($this->teacher);

    $response = getJson($this->url)->assertOk();

    expect($response->json('data.grades'))->toHaveCount($limit);
    $response->assertJsonPath('data.grades_truncated', true);
    // The beforeEach Math result + $limit new ones.
    expect(collect($response->json('data.by_subject'))->firstWhere('subject_name', 'Matemática')['assessment_count'])
        ->toBe($limit + 1);
});

it('does not flag a short grades list as truncated', function () {
    actingAs($this->teacher);

    getJson($this->url)->assertOk()->assertJsonPath('data.grades_truncated', false);
});

it('limits the group performance line to the teacher\'s own subjects when restricted', function () {
    actingAs($this->teacher);
    $groupUrl = "/api/v1/groups/{$this->group->id}/performance-timeline";

    // Own subject only (default): the History assessment's average — here a
    // single student's grade — is not exposed.
    $points = collect(getJson($groupUrl)->assertOk()->json('results'));
    expect($points->pluck('assessment_id')->all())->not->toContain($this->historyTest->id);
    expect($points)->toHaveCount(1);

    $this->school->update(['grades_visibility' => GradesVisibility::AllLive]);
    expect(getJson($groupUrl)->assertOk()->json('results'))->toHaveCount(2);
});

it('applies the periodic cutoff to the group performance line', function () {
    $this->travelTo(Carbon::parse('2026-07-02 10:00', SchoolTime::timezone()));
    $this->school->update([
        'grades_visibility' => GradesVisibility::AllPeriodic,
        'grades_cutoff_months' => 3,
        'grades_cutoff_anchor' => '2026-04-01',
    ]);
    $other = Student::factory()->for($this->school)->create();
    $this->group->students()->attach($other->id, ['school_year' => 2026]);
    $this->historyResult->forceFill(['created_at' => Carbon::parse('2026-06-15')])->save();
    AssessmentResult::factory()->for($this->historyTest)->for($other)->create([
        'score' => 9, 'created_at' => Carbon::parse('2026-07-01 15:00'),
    ]);
    actingAs($this->teacher);

    $history = collect(getJson("/api/v1/groups/{$this->group->id}/performance-timeline")->assertOk()->json('results'))
        ->firstWhere('assessment_id', $this->historyTest->id);

    // Only the result loaded before the cutoff counts.
    expect($history['results_count'])->toBe(1);
    expect((float) $history['average_score'])->toBe(3.0);
});

it('keeps the full group performance line for psychopedagogy and direction', function (string $role) {
    $user = User::factory()->create(['school_id' => $this->school->id]);
    $user->assignRole($role);
    actingAs($user);

    expect(getJson("/api/v1/groups/{$this->group->id}/performance-timeline")->assertOk()->json('results'))
        ->toHaveCount(2);
})->with([Role::Director->value, Role::Psychopedagogue->value]);
