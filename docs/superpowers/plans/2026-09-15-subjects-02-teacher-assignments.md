# Subjects — Session 2: Teacher-subject assignments (backend)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the teacher↔group link subject-specific — `group_teacher` gains a required `subject_id` so a row means "teacher T teaches subject S to group G" — and expose director-managed assignment endpoints plus the helper `User::teachesSubjectInGroup()` that Session 3 validation depends on.

**Architecture:** Add `subject_id` to the existing `group_teacher` pivot (uniqueness becomes `(group_id, teacher_id, subject_id)`, Option A — always one row per subject; no nullable "teaches everything"). Update `Group::teachers()` / `User::groups()` pivot columns, add `User::subjects()` (derived distinct) and `User::teachesSubjectInGroup()`. New `GroupTeacherAssignmentController` (director-only) to attach/detach.

**Tech Stack:** Laravel 13, PostgreSQL, Sanctum, Spatie, Pest, Sail.

## Global Constraints

- Depends on **Session 1** (the `Subject` model/table must exist). Do not start until Session 1 is merged into the working branch.
- All commands via Sail from `api/`. Before finishing: `./vendor/bin/sail bin pint` + `./vendor/bin/sail test` green.
- All code English; Spanish only in the frontend.
- `existsing` semantics of `teachesGroup` / `isLedBy` / `Group::scopeVisibleTo` must be **preserved** — they answer "does this user lead this group" via an `exists()` over the pivot, which still works with the extra column.
- Roles: director manages assignments. `App\Enums\Role`.
- Commit after each task. Trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

## File Structure

- Create: `api/database/migrations/2026_09_15_000002_add_subject_id_to_group_teacher_table.php` — adds the column + swaps the unique key.
- Modify: `api/app/Models/Group.php` — add `subject_id` to `teachers()->withPivot`.
- Modify: `api/app/Models/User.php` — add `subject_id` to `groups()->withPivot`; add `subjects()` and `teachesSubjectInGroup()`.
- Modify: `api/app/Models/Subject.php` — add `teachers()` / `groups()` belongsToMany through the pivot.
- Create: `api/app/Http/Requests/StoreGroupTeacherAssignmentRequest.php` — validate + authorize (director).
- Create: `api/app/Http/Controllers/GroupTeacherAssignmentController.php` — index/store/destroy assignments.
- Create: `api/app/Http/Resources/GroupTeacherAssignmentResource.php` — `{ teacher_id, subject_id, teacher_name, subject_name }`.
- Modify: `api/routes/api.php` — register assignment routes.
- Modify: `api/database/factories/GroupFactory.php` OR add a helper — see Task 1 note on seeding pivot rows in tests.
- Create: `api/tests/Feature/GroupTeacherAssignmentTest.php`.
- Create: `api/tests/Unit/UserTeachesSubjectTest.php`.

---

### Task 1: Migration — add `subject_id` to `group_teacher`

**Files:**
- Create: `api/database/migrations/2026_09_15_000002_add_subject_id_to_group_teacher_table.php`

**Interfaces:**
- Produces: `group_teacher.subject_id` (FK → subjects, not null), unique `(group_id, teacher_id, subject_id)`.

- [ ] **Step 1: Write the migration**

> No production data (project decision C2). If a dev DB already has `group_teacher` rows without a subject, the not-null add will fail — that's acceptable; run `./vendor/bin/sail artisan migrate:fresh` in dev. Tests run on a fresh DB so this is a non-issue there.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_teacher', function (Blueprint $table) {
            // A teacher-in-a-group assignment is per subject (Option A: one row
            // per subject, no nullable "teaches everything").
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['group_id', 'teacher_id']);
            $table->unique(['group_id', 'teacher_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('group_teacher', function (Blueprint $table) {
            $table->dropUnique(['group_id', 'teacher_id', 'subject_id']);
            $table->dropConstrainedForeignId('subject_id');
            $table->unique(['group_id', 'teacher_id']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `./vendor/bin/sail artisan migrate:fresh`
Expected: completes without error.

- [ ] **Step 3: Commit**

```bash
git add api/database/migrations/2026_09_15_000002_add_subject_id_to_group_teacher_table.php
git commit -m "feat(api): add subject_id to group_teacher pivot"
```

---

### Task 2: Model relations + `teachesSubjectInGroup` + `subjects()`

**Files:**
- Modify: `api/app/Models/Group.php`
- Modify: `api/app/Models/User.php`
- Modify: `api/app/Models/Subject.php`
- Create: `api/tests/Unit/UserTeachesSubjectTest.php`

**Interfaces:**
- Produces:
  - `User::teachesSubjectInGroup(Group $group, Subject $subject): bool` — true iff a `group_teacher` row exists for (this user, that group, that subject).
  - `User::subjects(): BelongsToMany` — distinct subjects across the user's assignments.
  - `Subject::teachers()` / `Subject::groups()` — belongsToMany through `group_teacher`.
  - `Group::teachers()` and `User::groups()` pivots now include `subject_id`.
- Consumes: `Subject` (Session 1), existing `teachesGroup`.

- [ ] **Step 1: Write the failing unit test** (`api/tests/Unit/UserTeachesSubjectTest.php`)

```php
<?php

use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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
```

- [ ] **Step 2: Run it to verify failure**

Run: `./vendor/bin/sail test --filter=UserTeachesSubjectTest`
Expected: FAIL (`teachesSubjectInGroup` / `subjects` undefined; attach with `subject_id` may fail until pivot columns declared).

- [ ] **Step 3: Update `Group::teachers()`** — add `subject_id` to `withPivot`:

```php
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_teacher', 'group_id', 'teacher_id')
            ->withPivot(['details', 'subject_id'])
            ->withTimestamps();
    }
```

- [ ] **Step 4: Update `User::groups()` and add the two helpers.** Add `use App\Models\Subject;` at the top. Replace `groups()` pivot and add methods:

```php
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_teacher', 'teacher_id', 'group_id')
            ->withPivot(['details', 'subject_id'])
            ->withTimestamps();
    }

    /**
     * The distinct set of subjects this user teaches, derived from their
     * group_teacher assignments (the flat "capability" list). Deliberately
     * distinct: the same subject taught to several groups is one subject here.
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'group_teacher', 'teacher_id', 'subject_id')
            ->distinct();
    }

    /**
     * Whether this user is assigned to teach the given subject in the given
     * group (a group_teacher row for this user + group + subject). Single
     * source of truth for the assessment-subject validation (Session 3).
     */
    public function teachesSubjectInGroup(Group $group, Subject $subject): bool
    {
        return $group->teachers()
            ->wherePivot('teacher_id', $this->id)
            ->wherePivot('subject_id', $subject->id)
            ->exists();
    }
```

- [ ] **Step 5: Add relations to `Subject`.** Add imports `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` and the methods:

```php
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_teacher', 'subject_id', 'teacher_id')
            ->distinct();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_teacher', 'subject_id', 'group_id')
            ->distinct();
    }
```

- [ ] **Step 6: Run the unit test to verify it passes**

Run: `./vendor/bin/sail test --filter=UserTeachesSubjectTest`
Expected: PASS.

- [ ] **Step 7: Run the existing group/policy suite to confirm nothing broke**

Run: `./vendor/bin/sail test --filter=Group`
Expected: PASS (teachesGroup / scopeVisibleTo unaffected).

- [ ] **Step 8: Commit**

```bash
git add api/app/Models/Group.php api/app/Models/User.php api/app/Models/Subject.php api/tests/Unit/UserTeachesSubjectTest.php
git commit -m "feat(api): add teacher-subject relations and teachesSubjectInGroup"
```

---

### Task 3: Assignment endpoints (director-managed)

**Files:**
- Create: `api/app/Http/Requests/StoreGroupTeacherAssignmentRequest.php`
- Create: `api/app/Http/Resources/GroupTeacherAssignmentResource.php`
- Create: `api/app/Http/Controllers/GroupTeacherAssignmentController.php`
- Modify: `api/routes/api.php`
- Create: `api/tests/Feature/GroupTeacherAssignmentTest.php`

**Interfaces:**
- Consumes: `User::teachesSubjectInGroup`, `Group`, `Subject`, `GroupPolicy` (director-only writes).
- Produces routes:
  - `GET  /api/v1/groups/{group}/teacher-assignments` — list `{ teacher_id, subject_id, teacher_name, subject_name }`.
  - `POST /api/v1/groups/{group}/teacher-assignments` — body `{ teacher_id, subject_id }` → attaches (idempotent), 201.
  - `DELETE /api/v1/groups/{group}/teacher-assignments` — body `{ teacher_id, subject_id }` → detaches that one pair, 204.

- [ ] **Step 1: Write the failing feature test** (`api/tests/Feature/GroupTeacherAssignmentTest.php`)

```php
<?php

use App\Enums\Role;
use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $this->group = Group::factory()->create();
    Tenancy::useSchool($this->group->school_id);
    $this->subject = Subject::factory()->for($this->group->school)->create(['name' => 'Matemática']);
    $this->teacher = User::factory()->create(['school_id' => $this->group->school_id]);
    $this->teacher->assignRole(Role::Teacher->value);
});

function director(Group $group): User
{
    $u = User::factory()->create(['school_id' => $group->school_id]);
    $u->assignRole(Role::Director->value);

    return $u;
}

it('lets a director assign a teacher to a subject in a group', function () {
    actingAs(director($this->group));

    postJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
    ])->assertCreated();

    expect($this->teacher->fresh()->teachesSubjectInGroup($this->group, $this->subject))->toBeTrue();
});

it('forbids a teacher from creating assignments', function () {
    actingAs($this->teacher);

    postJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
    ])->assertForbidden();
});

it('removes a single (teacher, subject) assignment', function () {
    $this->group->teachers()->attach($this->teacher->id, ['subject_id' => $this->subject->id]);
    actingAs(director($this->group));

    deleteJson("/api/v1/groups/{$this->group->id}/teacher-assignments", [
        'teacher_id' => $this->teacher->id,
        'subject_id' => $this->subject->id,
    ])->assertNoContent();

    expect($this->teacher->fresh()->teachesSubjectInGroup($this->group, $this->subject))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/sail test --filter=GroupTeacherAssignmentTest`
Expected: FAIL (routes 404).

- [ ] **Step 3: Write the Form Request**

```php
<?php

namespace App\Http\Requests;

use App\Models\Group;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assigning (or removing) a teacher-subject pair in a group. Director-only:
 * mirrors GroupPolicy::update (the director owns the group's staffing). The
 * teacher and subject must both belong to the current school.
 */
class StoreGroupTeacherAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Group $group */
        $group = $this->route('group');

        return $this->user()->can('update', $group);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $schoolId = Tenancy::schoolId();

        return [
            'teacher_id' => [
                'required', 'integer',
                // Must be a user in this school. (Role is enforced by product
                // convention; only teachers are assigned, but any staff row is
                // schema-valid — the UI only offers teachers.)
                Rule::exists('users', 'id')->where('school_id', $schoolId),
            ],
            'subject_id' => [
                'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', $schoolId),
            ],
        ];
    }
}
```

- [ ] **Step 4: Write the Resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One teacher-subject assignment row of a group. The underlying record is a
 * User hydrated with the `group_teacher` pivot (subject_id) plus the loaded
 * subject relation for its display name.
 */
class GroupTeacherAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'teacher_id' => $this->id,
            'teacher_name' => $this->name,
            'subject_id' => $this->pivot->subject_id,
            'subject_name' => $this->subjectName,
        ];
    }
}
```

- [ ] **Step 5: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGroupTeacherAssignmentRequest;
use App\Http\Resources\GroupTeacherAssignmentResource;
use App\Models\Group;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Director-managed teacher-subject assignments for a group (rows of the
 * group_teacher pivot). One row = "this teacher teaches this subject to this
 * group". Read is allowed to anyone who can view the group; writes require the
 * director (enforced by StoreGroupTeacherAssignmentRequest via GroupPolicy).
 */
class GroupTeacherAssignmentController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        $this->authorize('view', $group);

        // Each pivot row is one assignment. Map the (teacher, subject_id) rows
        // to a display shape, resolving subject names in one query.
        $rows = $group->teachers()->get();
        $subjectNames = Subject::query()
            ->whereIn('id', $rows->pluck('pivot.subject_id')->unique())
            ->pluck('name', 'id');

        $rows->each(function ($teacher) use ($subjectNames): void {
            $teacher->subjectName = $subjectNames[$teacher->pivot->subject_id] ?? null;
        });

        return GroupTeacherAssignmentResource::collection($rows);
    }

    public function store(StoreGroupTeacherAssignmentRequest $request, Group $group): JsonResponse
    {
        $data = $request->validated();

        // Idempotent attach: syncWithoutDetaching keyed by the pair avoids a
        // duplicate-key error on the (group, teacher, subject) unique index.
        $group->teachers()->syncWithoutDetaching([
            $data['teacher_id'] => ['subject_id' => $data['subject_id']],
        ]);

        return response()->json(status: 201);
    }

    public function destroy(StoreGroupTeacherAssignmentRequest $request, Group $group): Response
    {
        $data = $request->validated();

        $group->teachers()
            ->wherePivot('teacher_id', $data['teacher_id'])
            ->wherePivot('subject_id', $data['subject_id'])
            ->detach($data['teacher_id']);

        return response()->noContent();
    }
}
```

> Caveat on `detach`: `BelongsToMany::detach($id)` removes every pivot row for that teacher, ignoring the `wherePivot`. To delete only the one pair, use a direct pivot delete instead. Replace the `destroy` body with:
> ```php
>         \Illuminate\Support\Facades\DB::table('group_teacher')
>             ->where('group_id', $group->id)
>             ->where('teacher_id', $data['teacher_id'])
>             ->where('subject_id', $data['subject_id'])
>             ->delete();
> ```
> Use the DB-facade form (it is precise). Keep the `syncWithoutDetaching` attach as written.

- [ ] **Step 6: Register routes** — add import `use App\Http\Controllers\GroupTeacherAssignmentController;` and inside the `v1` group:

```php
        // Director-managed teacher-subject assignments per group.
        Route::get('/groups/{group}/teacher-assignments', [GroupTeacherAssignmentController::class, 'index']);
        Route::post('/groups/{group}/teacher-assignments', [GroupTeacherAssignmentController::class, 'store']);
        Route::delete('/groups/{group}/teacher-assignments', [GroupTeacherAssignmentController::class, 'destroy']);
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/sail test --filter=GroupTeacherAssignmentTest`
Expected: PASS.

- [ ] **Step 8: Format + full suite**

Run: `./vendor/bin/sail bin pint && ./vendor/bin/sail test`
Expected: clean + green.

- [ ] **Step 9: Commit**

```bash
git add api/app/Http/Requests/StoreGroupTeacherAssignmentRequest.php api/app/Http/Resources/GroupTeacherAssignmentResource.php api/app/Http/Controllers/GroupTeacherAssignmentController.php api/routes/api.php api/tests/Feature/GroupTeacherAssignmentTest.php
git commit -m "feat(api): add group teacher-subject assignment endpoints"
```

---

## Self-Review Checklist

- [ ] `group_teacher` has `subject_id` (not null) and unique `(group_id, teacher_id, subject_id)`.
- [ ] `teachesGroup` / `isLedBy` / `scopeVisibleTo` still pass their existing tests.
- [ ] `User::teachesSubjectInGroup` and `User::subjects()` (distinct) work per the unit test.
- [ ] Director can list/attach/detach; teacher gets 403 on writes.
- [ ] Detach removes only the single (teacher, subject) pair, not all of a teacher's subjects.
- [ ] pint clean, full suite green.
