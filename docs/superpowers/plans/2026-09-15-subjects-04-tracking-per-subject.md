# Subjects — Session 4: Per-subject tracking + chart subject filter (backend)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Slice the student tracking profile into general vs. per-subject statistics — add a `by_subject` block (average score + assessment count per subject) and an `overall_average` to `GET /students/{student}/tracking`, and add an optional `subject_id` filter to the performance timeline that drives the chart.

**Architecture:** Extend `StudentTrackingController::aggregate()` to compute academic per-subject aggregates (plain scalars — cache-safe, no role gating: scores are academic, not clinical) and surface them through `StudentTrackingResource`. Extend `PerformanceTimelineBuilder::build()`/`results()` and `StudentPerformanceTimelineRequest`/controller with an optional `subject_id` filter (the builder already joins `assessments`). Marks are not subject-scoped and are unchanged.

**Tech Stack:** Laravel 13, PostgreSQL, Sanctum, Pest, Sail.

## Global Constraints

- Depends on **Session 3** (`assessments.subject_id` exists). Do not start until Session 3 is merged.
- Per-subject figures are **academic**, shown to anyone who can view the student — do NOT gate them behind the clinical profile. Accommodations/barriers/alerts stay general and stay clinically gated exactly as today (decision D3/E).
- The tracking controller caches *raw scalars* for 60s and re-applies role filtering per request. The new per-subject data is plain scalars → safe to cache directly; it is not clinical, so it needs no per-request re-gating.
- All commands via Sail from `api/`. Before finishing: `./vendor/bin/sail bin pint` + `./vendor/bin/sail test` green.
- All code English; Spanish only in the frontend.
- Commit after each task. Trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

## File Structure

- Modify: `api/app/Http/Controllers/StudentTrackingController.php` — compute `by_subject` + `overall_average` in `aggregate()`; pass through in `show()`.
- Modify: `api/app/Http/Resources/StudentTrackingResource.php` — expose `by_subject` and `overall_average`.
- Modify: `api/app/Services/PerformanceTimelineBuilder.php` — add `$subjectId` to `build()` and `results()`.
- Modify: `api/app/Http/Controllers/StudentPerformanceTimelineController.php` — pass `subject_id` through.
- Modify: `api/app/Http/Requests/StudentPerformanceTimelineRequest.php` — validate optional `subject_id`.
- Create: `api/tests/Feature/StudentTrackingBySubjectTest.php`
- Create: `api/tests/Feature/StudentTimelineSubjectFilterTest.php`

---

### Task 1: Per-subject aggregates on the tracking view

**Files:**
- Modify: `api/app/Http/Controllers/StudentTrackingController.php`
- Modify: `api/app/Http/Resources/StudentTrackingResource.php`
- Create: `api/tests/Feature/StudentTrackingBySubjectTest.php`

**Interfaces:**
- Produces in the tracking JSON:
  - `overall_average: number|null` — mean of all the student's assessment-result scores (null if none).
  - `by_subject: Array<{ subject_id, subject_name, average, assessment_count }>` — one entry per subject the student has results in, ordered by subject name.

- [ ] **Step 1: Write the failing test** (`api/tests/Feature/StudentTrackingBySubjectTest.php`)

```php
<?php

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
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

    $mathA = Assessment::factory()->for($this->group)->create(['subject_id' => $math->id]);
    $engA = Assessment::factory()->for($this->group)->create(['subject_id' => $english->id]);

    AssessmentResult::factory()->for($mathA)->for($this->student)->create(['score' => 8]);
    AssessmentResult::factory()->for($mathA)->for($this->student)->create(['score' => 6]); // same subject → avg 7
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
```

> Confirm `AssessmentResultFactory` supports `->for($assessment)->for($student)`. If the two results per assessment collide on the `(assessment_id, student_id)` unique index, use two different assessments in the same subject instead (adjust the test to create `mathA` and `mathB` both with `$math->id`). Read the factory/migration first.

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/sail test --filter=StudentTrackingBySubjectTest`
Expected: FAIL (`by_subject`/`overall_average` absent).

- [ ] **Step 3: Compute the aggregates in the controller.** Add a helper and include its output in both the cached array and the resource payload. Add `use App\Models\AssessmentResult;` at the top.

Add to `aggregate()`'s returned array (these are plain scalars → cache-safe):

```php
            'by_subject' => $this->subjectAggregates($student),
            'overall_average' => $this->overallAverage($student),
```

Add the two helpers to the controller:

```php
    /**
     * Academic per-subject aggregates: for each subject the student has results
     * in, the mean score and number of scored assessments. Academic data — not
     * clinical — so it is cached and returned to anyone who can view the student
     * (no clinical gate). Ordered by subject name for a stable UI.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function subjectAggregates(Student $student): array
    {
        return AssessmentResult::query()
            ->where('assessment_results.student_id', $student->id)
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->join('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->groupBy('subjects.id', 'subjects.name')
            ->orderBy('subjects.name')
            ->selectRaw('subjects.id as subject_id, subjects.name as subject_name, '
                .'ROUND(AVG(assessment_results.score), 2) as average, '
                .'COUNT(*) as assessment_count')
            ->get()
            ->map(fn ($row) => [
                'subject_id' => (int) $row->subject_id,
                'subject_name' => $row->subject_name,
                'average' => (float) $row->average,
                'assessment_count' => (int) $row->assessment_count,
            ])
            ->all();
    }

    /**
     * Mean of all the student's assessment-result scores, or null if none.
     */
    protected function overallAverage(Student $student): ?float
    {
        $avg = AssessmentResult::query()
            ->where('student_id', $student->id)
            ->avg('score');

        return $avg === null ? null : round((float) $avg, 2);
    }
```

- [ ] **Step 4: Pass the new keys to the Resource.** In `show()`, extend the array given to `new StudentTrackingResource([...])`:

```php
        return new StudentTrackingResource([
            'student' => $student,
            'assessments' => $this->hydrate(Assessment::class, $cached['assessments']),
            'accommodations' => $this->hydrate(Accommodation::class, $cached['accommodations']),
            'barriers' => $this->hydrate(Barrier::class, $cached['barriers']),
            'comments' => $this->hydrate(Comment::class, $cached['comments']),
            'alerts' => $this->hydrate(Alert::class, $cached['alerts']),
            'by_subject' => $cached['by_subject'],
            'overall_average' => $cached['overall_average'],
        ]);
```

- [ ] **Step 5: Expose them in `StudentTrackingResource::toArray`** — add at the end of the returned array (no gating — academic data):

```php
            'overall_average' => $this->resource['overall_average'],
            'by_subject' => $this->resource['by_subject'],
```

- [ ] **Step 6: Run to verify pass**

Run: `./vendor/bin/sail test --filter=StudentTrackingBySubjectTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add api/app/Http/Controllers/StudentTrackingController.php api/app/Http/Resources/StudentTrackingResource.php api/tests/Feature/StudentTrackingBySubjectTest.php
git commit -m "feat(api): add per-subject averages and overall average to tracking"
```

---

### Task 2: `subject_id` filter on the performance timeline

**Files:**
- Modify: `api/app/Http/Requests/StudentPerformanceTimelineRequest.php`
- Modify: `api/app/Services/PerformanceTimelineBuilder.php`
- Modify: `api/app/Http/Controllers/StudentPerformanceTimelineController.php`
- Create: `api/tests/Feature/StudentTimelineSubjectFilterTest.php`

**Interfaces:**
- Consumes: `PerformanceTimelineBuilder`, `assessments.subject_id`.
- Produces: `GET /students/{student}/performance-timeline?subject_id=` — when present, `results` is limited to that subject; `marks` are unchanged. `subject_id` must belong to the current school.

- [ ] **Step 1: Write the failing test** (`api/tests/Feature/StudentTimelineSubjectFilterTest.php`)

```php
<?php

use App\Enums\Role;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
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
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/sail test --filter=StudentTimelineSubjectFilterTest`
Expected: FAIL (filter not applied; `subject_id=` currently ignored so first test returns 2 results).

- [ ] **Step 3: Validate `subject_id` in the request** — add to `StudentPerformanceTimelineRequest::rules()`. Add `use App\Support\Tenancy;` and `use Illuminate\Validation\Rule;`.

```php
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'subject_id' => [
                'nullable', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', Tenancy::schoolId()),
            ],
        ];
    }
```

- [ ] **Step 4: Thread `$subjectId` through the builder.** Update `PerformanceTimelineBuilder::build()` and `results()`:

```php
    public function build(Student $student, User $user, ?string $from, ?string $to, ?int $subjectId = null): array
    {
        return [
            'results' => $this->results($student, $from, $to, $subjectId),
            'marks' => $this->marks($student, $user, $from, $to),
        ];
    }
```

```php
    protected function results(Student $student, ?string $from, ?string $to, ?int $subjectId = null): Collection
    {
        return AssessmentResult::query()
            ->where('assessment_results.student_id', $student->id)
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->when($from, fn (Builder $q) => $q->whereDate('assessments.administered_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('assessments.administered_at', '<=', $to))
            ->when($subjectId, fn (Builder $q) => $q->where('assessments.subject_id', $subjectId))
            ->orderBy('assessments.administered_at')
            ->orderBy('assessment_results.id')
            ->select('assessment_results.*')
            ->with('assessment')
            ->get();
    }
```

- [ ] **Step 5: Pass it from the controller.** Update `StudentPerformanceTimelineController::show()`:

```php
        $timeline = $builder->build(
            $student,
            $request->user(),
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('subject_id') !== null ? (int) $request->validated('subject_id') : null,
        );
```

- [ ] **Step 6: Run to verify pass**

Run: `./vendor/bin/sail test --filter=StudentTimelineSubjectFilterTest`
Expected: PASS.

- [ ] **Step 7: Format + full suite**

Run: `./vendor/bin/sail bin pint && ./vendor/bin/sail test`
Expected: clean + green.

- [ ] **Step 8: Commit**

```bash
git add api/app/Http/Requests/StudentPerformanceTimelineRequest.php api/app/Services/PerformanceTimelineBuilder.php api/app/Http/Controllers/StudentPerformanceTimelineController.php api/tests/Feature/StudentTimelineSubjectFilterTest.php
git commit -m "feat(api): add subject_id filter to student performance timeline"
```

---

## Self-Review Checklist

- [ ] Tracking JSON has `overall_average` (null when no results) and `by_subject` (per-subject average + count, ordered by name).
- [ ] Per-subject data is returned to any viewer of the student (not clinically gated); accommodations/barriers/alerts stay gated as before.
- [ ] Timeline `?subject_id=` filters `results`; absent → all results; `marks` unchanged either way.
- [ ] Invalid/foreign `subject_id` → 422.
- [ ] The 60s tracking cache still returns correct data (raw scalars only; no hydrated models added).
- [ ] pint clean, full suite green.
