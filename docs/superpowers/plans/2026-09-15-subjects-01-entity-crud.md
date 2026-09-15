# Subjects — Session 1: Subject entity + CRUD + policy (backend)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a first-class, school-owned `Subject` entity ("materia") with full CRUD, owned by directors and readable by all staff.

**Architecture:** New tenant-scoped Eloquent model using the `BelongsToSchool` trait (auto-fills `school_id`, adds the `SchoolScope`) + `Auditable`. Standard Laravel stack: migration → model → policy → Form Requests → API resource → controller → routes. TDD with Pest feature tests.

**Tech Stack:** Laravel 13 (PHP 8.3+), PostgreSQL, Sanctum SPA auth, Spatie laravel-permission, Pest, Laravel Sail.

## Global Constraints

- All commands run through Sail from `api/`: `./vendor/bin/sail artisan …`, `./vendor/bin/sail test`, `./vendor/bin/sail bin pint`.
- Before finishing: `./vendor/bin/sail bin pint` (format) + `./vendor/bin/sail test` must pass.
- All code in English (identifiers, tables, columns, comments). User-facing Spanish labels live in the frontend only — none in this backend plan.
- Every business table has `school_id`; models `use App\Models\Concerns\BelongsToSchool`. `User` does NOT use the scope.
- Authorization is decided in Policies server-side. No policy may be "allow all" unless the resource is deliberately public (`viewAny` listing is allowed for authenticated staff, constrained by scope + `view`).
- Roles: `App\Enums\Role` = `teacher`, `director`, `psychopedagogue`. `Role::schoolWideValues()` = director + psychopedagogue.
- Commit after each task. Commit message trailer:
  ```
  Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
  ```

## File Structure

- Create: `api/database/migrations/2026_09_15_000001_create_subjects_table.php` — the `subjects` table.
- Create: `api/app/Models/Subject.php` — the model.
- Create: `api/database/factories/SubjectFactory.php` — test factory.
- Create: `api/app/Policies/SubjectPolicy.php` — director writes, all staff read.
- Create: `api/app/Http/Requests/StoreSubjectRequest.php` — create validation + authorize.
- Create: `api/app/Http/Requests/UpdateSubjectRequest.php` — update validation + authorize.
- Create: `api/app/Http/Resources/SubjectResource.php` — JSON shape.
- Create: `api/app/Http/Controllers/SubjectController.php` — CRUD.
- Modify: `api/routes/api.php` — register the resource routes (versioned `v1`).
- Create: `api/tests/Feature/SubjectCrudTest.php` — feature tests.

---

### Task 1: `subjects` table migration + model + factory

**Files:**
- Create: `api/database/migrations/2026_09_15_000001_create_subjects_table.php`
- Create: `api/app/Models/Subject.php`
- Create: `api/database/factories/SubjectFactory.php`

**Interfaces:**
- Produces: `App\Models\Subject` with fillable `['name', 'short_code', 'color']` (school_id auto-filled by trait), `use BelongsToSchool, Auditable, HasFactory, SoftDeletes`. `Subject::factory()`.

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
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('short_code')->nullable();
            $table->string('color')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // A school cannot have two subjects with the same name.
            $table->unique(['school_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A school subject ("materia", e.g. "Matemática"). School-owned (tenant-scoped
 * via BelongsToSchool) and managed by directors. `name` holds the school's own
 * Spanish label; `short_code`/`color` are optional presentation hints.
 */
#[Fillable(['name', 'short_code', 'color'])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use Auditable, BelongsToSchool, HasFactory, SoftDeletes;

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }
}
```

> Note: `assessments()` relation is defined now but `Assessment.subject_id` is added in Session 3. The relation compiles regardless (Eloquent resolves the column lazily); no Session-3 dependency here.

- [ ] **Step 3: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->unique()->randomElement([
                'Matemática', 'Lengua', 'Inglés', 'Biología', 'Historia',
                'Geografía', 'Física', 'Química', 'Arte', 'Educación Física',
            ]),
            'short_code' => fake()->optional()->lexify('???'),
            'color' => fake()->optional()->hexColor(),
        ];
    }
}
```

- [ ] **Step 4: Run the migration and a quick model check**

Run: `./vendor/bin/sail artisan migrate`
Then: `./vendor/bin/sail artisan tinker --execute="echo App\Models\Subject::factory()->make()->name;"`
Expected: a subject name prints, no errors.

- [ ] **Step 5: Commit**

```bash
git add api/database/migrations/2026_09_15_000001_create_subjects_table.php api/app/Models/Subject.php api/database/factories/SubjectFactory.php
git commit -m "feat(api): add Subject model, migration and factory"
```

---

### Task 2: `SubjectPolicy` (director writes, all staff read)

**Files:**
- Create: `api/app/Policies/SubjectPolicy.php`
- Create: `api/tests/Feature/SubjectCrudTest.php` (start the test file here)

**Interfaces:**
- Consumes: `App\Models\Subject`, `App\Enums\Role`.
- Produces: `SubjectPolicy` with `viewAny`/`view`/`create`/`update`/`delete`. Auto-discovered by Laravel naming convention.

- [ ] **Step 1: Write the failing test** (`api/tests/Feature/SubjectCrudTest.php`)

```php
<?php

use App\Enums\Role;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $this->school = School::factory()->create();
    Tenancy::useSchool($this->school);
});

function makeUser(School $school, Role $role): User
{
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role->value);

    return $user;
}

it('lets a director create a subject', function () {
    actingAs(makeUser($this->school, Role::Director));

    postJson('/api/v1/subjects', ['name' => 'Matemática'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Matemática');
});

it('forbids a teacher from creating a subject', function () {
    actingAs(makeUser($this->school, Role::Teacher));

    postJson('/api/v1/subjects', ['name' => 'Matemática'])
        ->assertForbidden();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=SubjectCrudTest`
Expected: FAIL (route `/api/v1/subjects` not defined → 404, or policy/class missing).

- [ ] **Step 3: Write the policy**

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Subject;
use App\Models\User;

/**
 * Subjects are a school catalog: any authenticated staff member reads them
 * (pickers and filters need the list), but only a director mutates them.
 * Tenant isolation is asserted first (defence-in-depth over SchoolScope).
 */
class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Subject $subject): bool
    {
        return $this->sharesSchool($user, $subject);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, Subject $subject): bool
    {
        return $this->sharesSchool($user, $subject)
            && $user->hasRole(Role::Director->value);
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $this->sharesSchool($user, $subject)
            && $user->hasRole(Role::Director->value);
    }

    protected function sharesSchool(User $user, Subject $subject): bool
    {
        return $user->school_id === $subject->school_id;
    }
}
```

- [ ] **Step 4: (defer running to Task 4)** — the route/controller don't exist yet; the test stays red until Task 4. Do not implement the controller here.

- [ ] **Step 5: Commit**

```bash
git add api/app/Policies/SubjectPolicy.php api/tests/Feature/SubjectCrudTest.php
git commit -m "feat(api): add SubjectPolicy and first CRUD tests"
```

---

### Task 3: Form Requests + Resource

**Files:**
- Create: `api/app/Http/Requests/StoreSubjectRequest.php`
- Create: `api/app/Http/Requests/UpdateSubjectRequest.php`
- Create: `api/app/Http/Resources/SubjectResource.php`

**Interfaces:**
- Consumes: `App\Models\Subject`, `SubjectPolicy`.
- Produces: `StoreSubjectRequest`, `UpdateSubjectRequest` (validated keys `name`, `short_code`, `color`), `SubjectResource` (shape `{ id, name, short_code, color }`).

- [ ] **Step 1: Write `StoreSubjectRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Models\Subject;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Subject::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                // Unique per school (matches the DB unique index).
                Rule::unique('subjects', 'name')
                    ->where('school_id', Tenancy::schoolId())
                    ->whereNull('deleted_at'),
            ],
            'short_code' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'max:32'],
        ];
    }
}
```

> Verify the helper name: `App\Support\Tenancy`. If `currentSchoolId()` does not exist, use `Tenancy::currentSchool()->id`. Confirm by reading `api/app/Support/Tenancy.php` before running.

- [ ] **Step 2: Write `UpdateSubjectRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Models\Subject;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Subject $subject */
        $subject = $this->route('subject');

        return $this->user()->can('update', $subject);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Subject $subject */
        $subject = $this->route('subject');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('subjects', 'name')
                    ->where('school_id', Tenancy::schoolId())
                    ->whereNull('deleted_at')
                    ->ignore($subject->id),
            ],
            'short_code' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'max:32'],
        ];
    }
}
```

- [ ] **Step 3: Write `SubjectResource`**

```php
<?php

namespace App\Http\Resources;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subject
 */
class SubjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_code' => $this->short_code,
            'color' => $this->color,
        ];
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add api/app/Http/Requests/StoreSubjectRequest.php api/app/Http/Requests/UpdateSubjectRequest.php api/app/Http/Resources/SubjectResource.php
git commit -m "feat(api): add Subject form requests and resource"
```

---

### Task 4: Controller + routes + green tests

**Files:**
- Create: `api/app/Http/Controllers/SubjectController.php`
- Modify: `api/routes/api.php`
- Modify: `api/tests/Feature/SubjectCrudTest.php` (add index/update/delete/tenant tests)

**Interfaces:**
- Consumes: all of Tasks 1-3.
- Produces: routes `GET/POST /api/v1/subjects`, `GET/PATCH/DELETE /api/v1/subjects/{subject}`.

- [ ] **Step 1: Add the remaining failing tests** (append to `SubjectCrudTest.php`)

```php
it('lists subjects for the current school only', function () {
    $otherSchool = School::factory()->create();
    Subject::factory()->for($otherSchool)->create(['name' => 'Foreign']);
    Subject::factory()->for($this->school)->create(['name' => 'Matemática']);

    actingAs(makeUser($this->school, Role::Teacher));

    getJson('/api/v1/subjects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Matemática');
});

it('rejects a duplicate subject name in the same school', function () {
    Subject::factory()->for($this->school)->create(['name' => 'Matemática']);
    actingAs(makeUser($this->school, Role::Director));

    postJson('/api/v1/subjects', ['name' => 'Matemática'])
        ->assertStatus(422);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test --filter=SubjectCrudTest`
Expected: FAIL (routes still 404).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * CRUD for school subjects ("materias"). Read is open to any authenticated
 * staff member (SchoolScope constrains rows to the tenant); writes are
 * director-only, enforced by StoreSubjectRequest/UpdateSubjectRequest via
 * SubjectPolicy.
 */
class SubjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subject::class);

        return SubjectResource::collection(
            Subject::query()->orderBy('name')->get()
        );
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $subject = Subject::create($request->validated());

        return (new SubjectResource($subject))->response()->setStatusCode(201);
    }

    public function show(Subject $subject): SubjectResource
    {
        $this->authorize('view', $subject);

        return new SubjectResource($subject);
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): SubjectResource
    {
        $subject->update($request->validated());

        return new SubjectResource($subject);
    }

    public function destroy(Subject $subject): Response
    {
        $this->authorize('delete', $subject);

        $subject->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 4: Register routes** — inside the `Route::prefix('v1')->group(...)` block in `api/routes/api.php`, add near the other resource routes. Also add the `use` import at the top.

```php
// top of file, with the other controller imports:
use App\Http\Controllers\SubjectController;

// inside Route::middleware('auth:sanctum')->group -> Route::prefix('v1')->group:
        // Subjects ("materias") catalog — director-managed, read by all staff.
        Route::apiResource('subjects', SubjectController::class);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail test --filter=SubjectCrudTest`
Expected: PASS (all cases).

- [ ] **Step 6: Format + full suite**

Run: `./vendor/bin/sail bin pint && ./vendor/bin/sail test`
Expected: pint clean, full suite green.

- [ ] **Step 7: Commit**

```bash
git add api/app/Http/Controllers/SubjectController.php api/routes/api.php api/tests/Feature/SubjectCrudTest.php
git commit -m "feat(api): add Subject CRUD controller and routes"
```

---

## Self-Review Checklist (run before declaring done)

- [ ] `subjects` table exists with `school_id`, unique `(school_id, name)`, soft deletes.
- [ ] `Subject` uses `BelongsToSchool` (auto-fills school_id) — confirmed by the tenant-isolation index test passing.
- [ ] Director can CRUD; teacher/psychopedagogue get 403 on writes, 200 on reads.
- [ ] Duplicate name in same school → 422; same name in a different school is allowed.
- [ ] `./vendor/bin/sail bin pint` clean and `./vendor/bin/sail test` green.
- [ ] Verified `App\Support\Tenancy` helper name used in the Form Requests actually exists.
