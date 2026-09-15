# Subjects — Session 3: Assessment.subject_id + AnnualPlan migration + validation (backend)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tie every assessment to a required `Subject`, validated against the teacher's per-group assignment; migrate `AnnualPlan.subject` (free-text string) to a `subject_id` FK; and update the AI-proposal annual-plan path (generate request, apply action, context builder) to carry `subject_id` instead of a free-text subject.

**Architecture:** Two migrations (assessments add `subject_id` NOT NULL; annual_plans add `subject_id` NOT NULL + drop `subject` string). Update models, factories, the assessment Form Requests (add the `teachesSubjectInGroup` rule), `AssessmentResource`, and three AI files. No production data (decision C2) → columns are NOT NULL from the start, no nullable/backfill phase.

**Tech Stack:** Laravel 13, PostgreSQL, Sanctum, Spatie, Pest, Sail.

## Global Constraints

- Depends on **Sessions 1 & 2** (Subject model + `User::teachesSubjectInGroup`). Do not start until both are merged into the working branch.
- All commands via Sail from `api/`. Before finishing: `./vendor/bin/sail bin pint` + `./vendor/bin/sail test` green (run the WHOLE suite — this session changes shared factories, so unrelated tests can break).
- No student PII to any third-party/AI call (CLAUDE.md rule 11). Subject *names* are fine to send; student names are not — this session only adds subject names to the AI context.
- All code English; Spanish only in the frontend.
- Commit after each task. Trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

## File Structure

- Create: `api/database/migrations/2026_09_15_000003_add_subject_id_to_assessments_table.php`
- Create: `api/database/migrations/2026_09_15_000004_replace_subject_with_subject_id_on_annual_plans_table.php`
- Modify: `api/app/Models/Assessment.php` — fillable + `subject()` relation.
- Modify: `api/app/Models/AnnualPlan.php` — fillable (`subject`→`subject_id`) + `subject()` relation.
- Modify: `api/database/factories/AssessmentFactory.php` — set `subject_id`.
- Modify: `api/database/factories/AnnualPlanFactory.php` — `subject`→`subject_id`.
- Modify: `api/app/Http/Requests/StoreAssessmentRequest.php` — add `subject_id` rule + `teachesSubjectInGroup`.
- Modify: `api/app/Http/Requests/UpdateAssessmentRequest.php` — add `subject_id` rule (validated against the assessment's group).
- Modify: `api/app/Http/Resources/AssessmentResource.php` — expose `subject_id` (+ optional subject name).
- Modify: `api/app/Http/Requests/GenerateAIProposalRequest.php` — `parameters.subject` (string) → `parameters.subject_id` (exists).
- Modify: `api/app/Actions/AI/ApplyProposal.php` — write `subject_id`.
- Modify: `api/app/Actions/AI/BuildProposalContext.php` — resolve `subject_id` → subject name for the AI text needle.
- Modify: `api/tests/Feature/AssessmentCrudTest.php` (or the existing assessment test file) — add subject cases.

---

### Task 1: `assessments.subject_id` migration + model + factory

**Files:**
- Create: `api/database/migrations/2026_09_15_000003_add_subject_id_to_assessments_table.php`
- Modify: `api/app/Models/Assessment.php`
- Modify: `api/database/factories/AssessmentFactory.php`

**Interfaces:**
- Produces: `assessments.subject_id` (FK → subjects, NOT NULL); `Assessment::subject()` belongsTo; `Assessment` fillable includes `subject_id`; `AssessmentFactory` always produces a `subject_id` in the same school as the group.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            // Every assessment is of exactly one subject (decision C1). NOT NULL
            // from the start — there is no production data to backfill (C2).
            $table->foreignId('subject_id')->after('group_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
        });
    }
};
```

- [ ] **Step 2: Update `Assessment`** — add `'subject_id'` to the `#[Fillable(...)]` list and add the relation (import already has `BelongsTo`):

```php
#[Fillable(['group_id', 'subject_id', 'teacher_id', 'type', 'purpose', 'duration_minutes', 'content', 'variant_number', 'administered_at'])]
```

```php
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
```

- [ ] **Step 3: Update `AssessmentFactory`** — ensure `subject_id` is set and belongs to the group's school. Replace the `configure()`/`definition()` so the after-hook fills both school_id and subject_id from the group:

```php
    public function configure(): static
    {
        return $this->afterMaking(function (Assessment $assessment): void {
            $assessment->school_id ??= $assessment->group?->school_id;
            $assessment->subject_id ??= \App\Models\Subject::factory()
                ->for($assessment->group)
                ->create()->id;
        });
    }
```

> `->for($assessment->group)` puts the subject in the group's school (the Subject factory otherwise mints a new school). `$assessment->group` is available because `group_id` was resolved from `Group::factory()` in `definition()`. If a test passes an explicit `group_id` integer without a school, prefer `Assessment::factory()->for($group)` in that test.

- [ ] **Step 4: Migrate + smoke check**

Run: `./vendor/bin/sail artisan migrate:fresh`
Then: `./vendor/bin/sail artisan tinker --execute="\$a = App\Models\Assessment::factory()->create(); echo \$a->subject_id;"`
Expected: a non-empty subject_id prints; no error.

- [ ] **Step 5: Commit**

```bash
git add api/database/migrations/2026_09_15_000003_add_subject_id_to_assessments_table.php api/app/Models/Assessment.php api/database/factories/AssessmentFactory.php
git commit -m "feat(api): add required subject_id to assessments"
```

---

### Task 2: Assessment Form Requests — subject required + assignment-validated

**Files:**
- Modify: `api/app/Http/Requests/StoreAssessmentRequest.php`
- Modify: `api/app/Http/Requests/UpdateAssessmentRequest.php`
- Modify: `api/app/Http/Resources/AssessmentResource.php`
- Modify/Create: the assessment feature test file (`api/tests/Feature/AssessmentCrudTest.php` — confirm the exact existing filename first; there is an assessment CRUD test from Session 6).

**Interfaces:**
- Consumes: `User::teachesSubjectInGroup` (Session 2), `Subject`.
- Produces: `subject_id` required in both requests; store rejects a subject the teacher isn't assigned in the target group (422); `AssessmentResource` returns `subject_id` and `subject_name`.

- [ ] **Step 1: Write the failing test** — add to the assessment feature test file. Adjust the `beforeEach` to match the existing file's setup (school, teacher, group). Core new cases:

```php
it('creates an assessment for a subject the teacher teaches in the group', function () {
    // $this->teacher, $this->group set up in beforeEach and assigned below.
    $subject = \App\Models\Subject::factory()->for($this->group->school)->create(['name' => 'Matemática']);
    $this->group->teachers()->syncWithoutDetaching([$this->teacher->id => ['subject_id' => $subject->id]]);

    actingAs($this->teacher);

    postJson("/api/v1/groups/{$this->group->id}/assessments", [
        'type' => 'written',
        'administered_at' => '2026-09-01',
        'subject_id' => $subject->id,
    ])->assertCreated()->assertJsonPath('data.subject_id', $subject->id);
});

it('rejects an assessment for a subject the teacher is not assigned in the group', function () {
    $subject = \App\Models\Subject::factory()->for($this->group->school)->create(['name' => 'Inglés']);
    // Note: no assignment attached for this teacher/subject/group.
    actingAs($this->teacher);

    postJson("/api/v1/groups/{$this->group->id}/assessments", [
        'type' => 'written',
        'administered_at' => '2026-09-01',
        'subject_id' => $subject->id,
    ])->assertStatus(422);
});
```

> The existing store test (without `subject_id`) will now 422 — update it to attach an assignment and pass `subject_id`, as in the first case above.

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/sail test --filter=Assessment`
Expected: FAIL (subject_id not validated yet; assignment rule missing).

- [ ] **Step 3: Update `StoreAssessmentRequest`** — add a `subject_id` rule and enforce the assignment. Add imports `use App\Models\Subject;`, `use App\Support\Tenancy;`, `use Closure;`.

```php
    public function rules(): array
    {
        /** @var Group $group */
        $group = $this->route('group');

        return [
            'type' => ['required', new Enum(AssessmentType::class)],
            'purpose' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'administered_at' => ['required', 'date'],
            'subject_id' => [
                'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', Tenancy::schoolId()),
                // The teacher must actually be assigned this subject in this
                // group (single source of truth: User::teachesSubjectInGroup).
                function (string $attribute, mixed $value, Closure $fail) use ($group): void {
                    $subject = Subject::find($value);
                    if ($subject === null || ! $this->user()->teachesSubjectInGroup($group, $subject)) {
                        $fail('You are not assigned to teach this subject in this group.');
                    }
                },
            ],
        ];
    }
```

Add `use Illuminate\Validation\Rule;` to the imports.

- [ ] **Step 4: Update `UpdateAssessmentRequest`** — add a `sometimes` subject rule validated against the assessment's own group. Add imports `use App\Models\Subject;`, `use App\Support\Tenancy;`, `use Illuminate\Validation\Rule;`, `use Closure;`.

```php
    public function rules(): array
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return [
            'type' => ['sometimes', 'required', new Enum(AssessmentType::class)],
            'purpose' => ['sometimes', 'nullable', 'string'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'administered_at' => ['sometimes', 'required', 'date'],
            'subject_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', Tenancy::schoolId()),
                function (string $attribute, mixed $value, Closure $fail) use ($assessment): void {
                    $subject = Subject::find($value);
                    if ($subject === null || ! $this->user()->teachesSubjectInGroup($assessment->group, $subject)) {
                        $fail('You are not assigned to teach this subject in this group.');
                    }
                },
            ],
        ];
    }
```

- [ ] **Step 5: Update `AssessmentResource`** — add subject fields after `group_id`:

```php
            'group_id' => $this->group_id,
            'subject_id' => $this->subject_id,
            'subject_name' => $this->whenLoaded('subject', fn () => $this->subject->name),
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/sail test --filter=Assessment`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add api/app/Http/Requests/StoreAssessmentRequest.php api/app/Http/Requests/UpdateAssessmentRequest.php api/app/Http/Resources/AssessmentResource.php api/tests/Feature/AssessmentCrudTest.php
git commit -m "feat(api): require and validate assessment subject against teacher assignment"
```

---

### Task 3: Migrate `AnnualPlan.subject` string → `subject_id` FK

**Files:**
- Create: `api/database/migrations/2026_09_15_000004_replace_subject_with_subject_id_on_annual_plans_table.php`
- Modify: `api/app/Models/AnnualPlan.php`
- Modify: `api/database/factories/AnnualPlanFactory.php`

**Interfaces:**
- Produces: `annual_plans.subject_id` (FK → subjects, NOT NULL); the `subject` string column is dropped; `AnnualPlan::subject()` belongsTo; fillable uses `subject_id`; `AnnualPlanFactory` sets `subject_id`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_plans', function (Blueprint $table) {
            // No production data (C2): drop the free-text subject and replace it
            // with an FK to the school's Subject catalog.
            $table->dropColumn('subject');
            $table->foreignId('subject_id')->after('group_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('annual_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
            $table->string('subject');
        });
    }
};
```

- [ ] **Step 2: Update `AnnualPlan`** — swap `subject` for `subject_id` in fillable and add the relation:

```php
#[Fillable([
    'group_id',
    'subject_id',
    'curricular_framework_id',
    'teacher_id',
    'student_id',
    'description',
    'year',
    'language',
])]
```

```php
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
```

- [ ] **Step 3: Update `AnnualPlanFactory`** — replace the `'subject' => …` line. Open the factory and set:

```php
            'subject_id' => \App\Models\Subject::factory(),
```

> If the factory sets `group_id` via `Group::factory()` and `school_id` from the group, ensure the subject lands in the same school. Prefer an `afterMaking` hook mirroring AssessmentFactory:
> ```php
>     public function configure(): static
>     {
>         return $this->afterMaking(function (\App\Models\AnnualPlan $plan): void {
>             $plan->subject_id ??= \App\Models\Subject::factory()->for($plan->group)->create()->id;
>         });
>     }
> ```
> and remove any hardcoded `subject_id`/`subject` from `definition()`. Read the existing factory first and adapt to its structure.

- [ ] **Step 4: Migrate + run the annual-plan tests**

Run: `./vendor/bin/sail artisan migrate:fresh && ./vendor/bin/sail test --filter=AnnualPlan`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/database/migrations/2026_09_15_000004_replace_subject_with_subject_id_on_annual_plans_table.php api/app/Models/AnnualPlan.php api/database/factories/AnnualPlanFactory.php
git commit -m "feat(api): replace annual_plan free-text subject with subject_id FK"
```

---

### Task 4: AI proposal path — carry `subject_id` not free-text

**Files:**
- Modify: `api/app/Http/Requests/GenerateAIProposalRequest.php`
- Modify: `api/app/Actions/AI/ApplyProposal.php`
- Modify: `api/app/Actions/AI/BuildProposalContext.php`
- Modify: the AI proposal feature test (confirm filename, e.g. `api/tests/Feature/AIProposalTest.php`).

**Interfaces:**
- Consumes: `Subject`, `AnnualPlan.subject_id`.
- Produces: annual-plan proposals accept `parameters.subject_id` (exists in school); `ApplyProposal::applyAnnualPlan` writes `subject_id`; `BuildProposalContext` uses the subject *name* (resolved from the id) as its text needle.

- [ ] **Step 1: Write/adjust the failing test** — in the AI proposal test, the annual-plan generate payload must send `subject_id` instead of `subject`. Add a subject and reference it:

```php
$subject = \App\Models\Subject::factory()->for($group->school)->create(['name' => 'Matemática']);

$payload = [
    'type' => 'annual_plan',
    'parameters' => [
        'curricular_framework_id' => $framework->id,
        'subject_id' => $subject->id,
        'year' => 2026,
    ],
];
```

And assert the applied AnnualPlan has `subject_id === $subject->id`. Update any existing annual-plan proposal test that sends `'subject' => 'Matemática'`.

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/sail test --filter=AIProposal`
Expected: FAIL (request still requires `parameters.subject` string; apply still writes `subject`).

- [ ] **Step 3: Update `GenerateAIProposalRequest`** — replace the annual-plan `parameters.subject` rule:

```php
            AIProposalType::AnnualPlan => [
                'parameters.curricular_framework_id' => ['required', $this->frameworkBelongsToGroup()],
                'parameters.subject_id' => [
                    'required',
                    Rule::exists('subjects', 'id')->where('school_id', $this->user()->school_id),
                ],
                'parameters.year' => ['required', 'integer'],
                'parameters.student_id' => ['nullable', $this->studentBelongsToGroup()],
                'parameters.focus' => ['nullable', 'string'],
                'parameters.language' => ['nullable', 'string'],
            ],
```

`Rule` is already imported in this file.

- [ ] **Step 4: Update `ApplyProposal::applyAnnualPlan`** — change the write:

```php
        return AnnualPlan::create([
            'group_id' => $proposal->group_id,
            'curricular_framework_id' => $params['curricular_framework_id'],
            'teacher_id' => $applier->id,
            'student_id' => $params['student_id'] ?? null,
            'subject_id' => $params['subject_id'],
            'year' => $params['year'],
            'language' => $params['language'] ?? self::DEFAULT_LANGUAGE,
            'description' => $raw['description'],
        ]);
```

- [ ] **Step 5: Update `BuildProposalContext`** — the needle previously read `$parameters['subject']` (a string). Resolve the subject *name* from `subject_id` so the AI still gets a subject label (name only — never student PII). Around line 60:

```php
        $subjectName = isset($parameters['subject_id'])
            ? (\App\Models\Subject::find($parameters['subject_id'])?->name ?? '')
            : '';
        $needle = mb_strtolower(trim((string) ($subjectName ?: ($parameters['focus'] ?? ''))));
```

> Read the surrounding method first and preserve its existing behavior; only swap the source of the subject label from the free-text `subject` param to the resolved subject name. Add `use App\Models\Subject;` if not present.

- [ ] **Step 6: Run to verify pass**

Run: `./vendor/bin/sail test --filter=AIProposal`
Expected: PASS.

- [ ] **Step 7: Format + FULL suite** (shared factories changed):

Run: `./vendor/bin/sail bin pint && ./vendor/bin/sail test`
Expected: clean + fully green. Fix any test that still sends the old `subject` string or omits `subject_id`.

- [ ] **Step 8: Commit**

```bash
git add api/app/Http/Requests/GenerateAIProposalRequest.php api/app/Actions/AI/ApplyProposal.php api/app/Actions/AI/BuildProposalContext.php api/tests/Feature/AIProposalTest.php
git commit -m "feat(api): carry subject_id through the AI annual-plan proposal path"
```

---

## Self-Review Checklist

- [ ] `assessments.subject_id` and `annual_plans.subject_id` are NOT NULL FKs; `annual_plans.subject` string is gone.
- [ ] Creating an assessment requires `subject_id`, and a subject the teacher isn't assigned in that group → 422.
- [ ] `AssessmentResource` returns `subject_id` (+ `subject_name` when loaded).
- [ ] AI annual-plan proposals validate `parameters.subject_id`; `ApplyProposal` writes it; `BuildProposalContext` sends the subject *name* only (no student PII).
- [ ] Shared factories (`AssessmentFactory`, `AnnualPlanFactory`) always produce a subject in the correct school.
- [ ] `./vendor/bin/sail bin pint` clean and the FULL `./vendor/bin/sail test` suite is green.
