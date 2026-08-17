# Clases y Alumnos (CRUD) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the first real business feature — CRUD for clases (`groups`) and alumnos (`students`) — end to end: backend endpoints + policies, and the SPA screens to use them.

**Architecture:** Extend the existing `Group`/`Student` models in place (no new abstractions). Standard Laravel `apiResource` controllers backed by Form Requests (validation) and Policies (authorization, already scaffolded). React feature folders (`features/groups`, `features/students`) mirroring the existing `features/auth` pattern: a thin `*Api.ts` client + full-page list/form components, no modals, no generic CRUD abstraction.

**Tech Stack:** Laravel 13 (Pest, Form Requests, Policies, Eloquent Resources), React 19 + TypeScript (react-hook-form + Zod, axios, react-router-dom v7), Tailwind v4 / shadcn "new-york" style primitives.

## Global Constraints

- Every table/column/identifier in English; no Spanish in code (`docs/superpowers/specs/2026-08-16-classes-students-crud-design.md`, `CLAUDE.md`).
- All user-facing UI text in Spanish (labels, buttons, errors).
- Authorization is decided server-side in Policies; the frontend only hides buttons for UX, never as the security boundary.
- All input validated server-side via Form Requests, regardless of Zod.
- Crear/editar clases y alumnos es exclusivo del rol `director` (spec decision — stricter than the pre-existing reference policies).
- Un alumno pertenece a una sola clase a la vez (`group_id` nullable, sin historial).
- Baja de alumno = `status: inactive`, no hard delete (hard delete queda solo para `destroy`, director-only, uso excepcional).
- Before considering the feature done: `sail bin pint`, `sail test`, `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build` must all pass (mirrors `.github/workflows/ci.yml`).

---

## Task 1: `StudentStatus` enum + student profile columns

**Files:**
- Create: `api/app/Enums/StudentStatus.php`
- Create: `api/database/migrations/2026_08_16_000001_add_profile_fields_to_students_table.php`
- Modify: `api/app/Models/Student.php`
- Test: `api/tests/Feature/StudentProfileFieldsTest.php`

**Interfaces:**
- Produces: `App\Enums\StudentStatus` (cases `Active = 'active'`, `Inactive = 'inactive'`), used by later tasks' Form Requests, Resource, and Policy tests. `Student` model gains fillable `status`, `family_contact_name`, `family_contact_phone`, `family_contact_email`, `pedagogical_notes`, with `status` cast to `StudentStatus`.

- [ ] **Step 1: Write the enum**

```php
<?php

namespace App\Enums;

/**
 * Whether a student is currently enrolled. Deactivating a student (e.g. they
 * left the school) flips this instead of deleting the row — history stays
 * intact. Hard delete remains available (director-only) for load mistakes.
 */
enum StudentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
```

- [ ] **Step 2: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('status')->default('active')->after('birth_date');
            $table->string('family_contact_name')->nullable()->after('status');
            $table->string('family_contact_phone')->nullable()->after('family_contact_name');
            $table->string('family_contact_email')->nullable()->after('family_contact_phone');
            $table->text('pedagogical_notes')->nullable()->after('family_contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'family_contact_name',
                'family_contact_phone',
                'family_contact_email',
                'pedagogical_notes',
            ]);
        });
    }
};
```

- [ ] **Step 3: Update the `Student` model**

Replace the `#[Fillable(...)]` attribute and `casts()` method in `api/app/Models/Student.php`:

```php
#[Fillable([
    'first_name',
    'last_name',
    'birth_date',
    'group_id',
    'status',
    'family_contact_name',
    'family_contact_phone',
    'family_contact_email',
    'pedagogical_notes',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use BelongsToSchool, HasFactory;

    protected $table = 'students';

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'status' => StudentStatus::class,
        ];
    }
```

Add the import at the top: `use App\Enums\StudentStatus;`

- [ ] **Step 4: Write the failing test**

```php
<?php

use App\Enums\StudentStatus;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Support\Tenancy;

it('defaults a new student to active status and casts it to the enum', function () {
    $school = School::factory()->create();

    $student = Tenancy::forSchool($school, fn () => Student::create([
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
    ]));

    expect($student->status)->toBe(StudentStatus::Active)
        ->and($student->fresh()->status)->toBe(StudentStatus::Active);
});

it('stores the new profile fields on a student', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id]);

    $student = Tenancy::forSchool($school, fn () => Student::create([
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
        'group_id' => $group->id,
        'status' => StudentStatus::Inactive->value,
        'family_contact_name' => 'Marcela Gómez',
        'family_contact_phone' => '+54 9 11 5555-5555',
        'family_contact_email' => 'marcela@example.com',
        'pedagogical_notes' => 'Necesita seguimiento en lectoescritura.',
    ]));

    expect($student->status)->toBe(StudentStatus::Inactive)
        ->and($student->family_contact_name)->toBe('Marcela Gómez')
        ->and($student->pedagogical_notes)->toBe('Necesita seguimiento en lectoescritura.');
});
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `cd api && ./vendor/bin/sail test --filter=StudentProfileFieldsTest`
Expected: FAIL (column `status` does not exist yet — migration not run) or class not found, since the migration/model edit above weren't applied to a fresh test DB run yet. If you already applied Steps 1–3 before running, skip straight to Step 6.

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd api && ./vendor/bin/sail test --filter=StudentProfileFieldsTest`
Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add api/app/Enums/StudentStatus.php api/database/migrations/2026_08_16_000001_add_profile_fields_to_students_table.php api/app/Models/Student.php api/tests/Feature/StudentProfileFieldsTest.php
git commit -m "feat: add student profile fields (status, family contact, pedagogical notes)"
```

---

## Task 2: Tighten `GroupPolicy::update` to director-only

**Files:**
- Modify: `api/app/Policies/GroupPolicy.php`
- Modify: `api/tests/Feature/Authorization/GroupPolicyTest.php`

**Interfaces:**
- Produces: `GroupPolicy::update()` now returns `true` only for `Role::Director`, matching `delete()`. No signature change.

- [ ] **Step 1: Update the failing assertion first**

In `api/tests/Feature/Authorization/GroupPolicyTest.php`, replace the `'lets a teacher view only groups they lead'` test:

```php
it('lets a teacher view groups they lead, but not update or delete them', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();

    $own = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    $other = Group::factory()->create(['school_id' => $school->id]);

    expect($teacher->can('view', $own))->toBeTrue()
        ->and($teacher->can('view', $other))->toBeFalse()
        ->and($teacher->can('update', $own))->toBeFalse()
        ->and($teacher->can('delete', $own))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd api && ./vendor/bin/sail test --filter=GroupPolicyTest`
Expected: FAIL on `$teacher->can('update', $own)` — currently `true`, test now expects `false`.

- [ ] **Step 3: Update the policy**

In `api/app/Policies/GroupPolicy.php`, replace `update()`:

```php
    public function update(User $user, Group $group): bool
    {
        return $this->sharesSchool($user, $group)
            && $user->hasRole(Role::Director->value);
    }
```

`leadsGroup()` stays defined (still used by `view()`) — do not delete it.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd api && ./vendor/bin/sail test --filter=GroupPolicyTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add api/app/Policies/GroupPolicy.php api/tests/Feature/Authorization/GroupPolicyTest.php
git commit -m "fix: restrict group update to directors only"
```

---

## Task 3: Tighten `StudentPolicy::create`/`update` to director-only + new policy tests

**Files:**
- Modify: `api/app/Policies/StudentPolicy.php`
- Create: `api/tests/Feature/Authorization/StudentPolicyTest.php`

**Interfaces:**
- Produces: `StudentPolicy::create()` and `::update()` now return `true` only for `Role::Director`. `view()`/`viewAny()`/`delete()` unchanged.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Role;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;

it('lets a director view, create and update any student in their school', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    expect($director->can('view', $student))->toBeTrue()
        ->and($director->can('create', Student::class))->toBeTrue()
        ->and($director->can('update', $student))->toBeTrue();
});

it('lets a psychopedagogue view any student but not create or update one', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);

    expect($psychopedagogue->can('view', $student))->toBeTrue()
        ->and($psychopedagogue->can('create', Student::class))->toBeFalse()
        ->and($psychopedagogue->can('update', $student))->toBeFalse();
});

it('lets a teacher view only students in a group they lead, and never create or update', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $ownGroup = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);

    $own = Student::factory()->create(['school_id' => $school->id, 'group_id' => $ownGroup->id]);
    $other = Student::factory()->create(['school_id' => $school->id, 'group_id' => $otherGroup->id]);

    expect($teacher->can('view', $own))->toBeTrue()
        ->and($teacher->can('view', $other))->toBeFalse()
        ->and($teacher->can('create', Student::class))->toBeFalse()
        ->and($teacher->can('update', $own))->toBeFalse();
});

it('never authorizes across schools even for a director', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);

    expect($directorA->can('view', $studentB))->toBeFalse()
        ->and($directorA->can('update', $studentB))->toBeFalse()
        ->and($directorA->can('delete', $studentB))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd api && ./vendor/bin/sail test --filter=StudentPolicyTest`
Expected: FAIL — `$psychopedagogue->can('update', $student)` and `$psychopedagogue->can('create', ...)` currently return `true`.

- [ ] **Step 3: Update the policy**

In `api/app/Policies/StudentPolicy.php`, replace `create()` and `update()`:

```php
    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, Student $student): bool
    {
        return $this->sharesSchool($user, $student)
            && $user->hasRole(Role::Director->value);
    }
```

Also update the docblock above `update()`'s old comment referencing "Director and psychopedagogue" if present, to avoid a stale comment — replace the block comment above `create()`:

```php
    /**
     * Director-only: creating and editing student records is restricted to
     * the director. Psychopedagogue keeps full read access (see view/viewAny)
     * but does not write.
     */
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd api && ./vendor/bin/sail test --filter=StudentPolicyTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add api/app/Policies/StudentPolicy.php api/tests/Feature/Authorization/StudentPolicyTest.php
git commit -m "fix: restrict student create/update to directors only"
```

---

## Task 4: `GroupResource` and `StudentResource`

**Files:**
- Create: `api/app/Http/Resources/GroupResource.php`
- Create: `api/app/Http/Resources/StudentResource.php`
- Test: `api/tests/Feature/GroupResourceTest.php`
- Test: `api/tests/Feature/StudentResourceTest.php`

**Interfaces:**
- Produces: `GroupResource` shape `{id, name, level, year, teacher_id, teacher: {id, name}|null}`. `StudentResource` shape `{id, first_name, last_name, full_name, birth_date, status, family_contact_name, family_contact_phone, family_contact_email, pedagogical_notes, group_id, group: {id, name}|null}`. Both consumed by Task 6/7 controllers and by the frontend types in Task 8.

- [ ] **Step 1: Write the failing tests**

```php
// api/tests/Feature/GroupResourceTest.php
<?php

use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Models\School;
use App\Models\User;

it('serializes a group with its teacher', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create(['name' => 'Ana Pérez']);
    $group = Group::factory()->create([
        'school_id' => $school->id,
        'teacher_id' => $teacher->id,
        'name' => '3° A',
        'level' => 'Primaria',
        'year' => '2026',
    ])->load('teacher');

    $array = (new GroupResource($group))->toArray(request());

    expect($array)->toBe([
        'id' => $group->id,
        'name' => '3° A',
        'level' => 'Primaria',
        'year' => '2026',
        'teacher_id' => $teacher->id,
        'teacher' => ['id' => $teacher->id, 'name' => 'Ana Pérez'],
    ]);
});

it('serializes a group without a teacher as null', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => null])->load('teacher');

    expect((new GroupResource($group))->toArray(request())['teacher'])->toBeNull();
});
```

```php
// api/tests/Feature/StudentResourceTest.php
<?php

use App\Enums\StudentStatus;
use App\Http\Resources\StudentResource;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;

it('serializes a student with its group and profile fields', function () {
    $school = School::factory()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'name' => '3° A']);
    $student = Student::factory()->create([
        'school_id' => $school->id,
        'group_id' => $group->id,
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
        'status' => StudentStatus::Active,
        'family_contact_name' => 'Marcela Gómez',
    ])->load('group');

    $array = (new StudentResource($student))->toArray(request());

    expect($array['full_name'])->toBe('Ana Gómez')
        ->and($array['status'])->toBe('active')
        ->and($array['group'])->toBe(['id' => $group->id, 'name' => '3° A'])
        ->and($array['family_contact_name'])->toBe('Marcela Gómez');
});

it('serializes a student without a group as null', function () {
    $school = School::factory()->create();
    $student = Student::factory()->create(['school_id' => $school->id, 'group_id' => null])->load('group');

    expect((new StudentResource($student))->toArray(request())['group'])->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd api && ./vendor/bin/sail test --filter=GroupResourceTest --filter=StudentResourceTest`
Expected: FAIL — class `App\Http\Resources\GroupResource` not found.

- [ ] **Step 3: Write `GroupResource`**

```php
<?php

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Group
 */
class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'level' => $this->level,
            'year' => $this->year,
            'teacher_id' => $this->teacher_id,
            'teacher' => $this->teacher ? [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
            ] : null,
        ];
    }
}
```

- [ ] **Step 4: Write `StudentResource`**

```php
<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Student
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'status' => $this->status->value,
            'family_contact_name' => $this->family_contact_name,
            'family_contact_phone' => $this->family_contact_phone,
            'family_contact_email' => $this->family_contact_email,
            'pedagogical_notes' => $this->pedagogical_notes,
            'group_id' => $this->group_id,
            'group' => $this->group ? [
                'id' => $this->group->id,
                'name' => $this->group->name,
            ] : null,
        ];
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd api && ./vendor/bin/sail test --filter=GroupResourceTest --filter=StudentResourceTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add api/app/Http/Resources/GroupResource.php api/app/Http/Resources/StudentResource.php api/tests/Feature/GroupResourceTest.php api/tests/Feature/StudentResourceTest.php
git commit -m "feat: add GroupResource and StudentResource"
```

---

## Task 5: Form Requests for groups and students

**Files:**
- Create: `api/app/Http/Requests/StoreGroupRequest.php`
- Create: `api/app/Http/Requests/UpdateGroupRequest.php`
- Create: `api/app/Http/Requests/StoreStudentRequest.php`
- Create: `api/app/Http/Requests/UpdateStudentRequest.php`

**Interfaces:**
- Consumes: `App\Enums\StudentStatus` (Task 1), `App\Models\Group`/`App\Models\Student` policies (Task 2/3).
- Produces: these four classes, consumed directly by the controllers in Task 6/7 (`$request->validated()`).

There's no isolated test for Form Requests in this codebase's existing pattern (`LoginRequest` has none) — they're exercised through the controller feature tests in Task 6/7. Write all four now; verify them there.

- [ ] **Step 1: `StoreGroupRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Group::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'string', 'max:255'],
            'teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('school_id', $this->user()->school_id)
                ),
            ],
        ];
    }
}
```

- [ ] **Step 2: `UpdateGroupRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('group'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'string', 'max:255'],
            'teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('school_id', $this->user()->school_id)
                ),
            ],
        ];
    }
}
```

- [ ] **Step 3: `StoreStudentRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Enums\StudentStatus;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Student::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'group_id' => [
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where(
                    fn ($query) => $query->where('school_id', $this->user()->school_id)
                ),
            ],
            'status' => ['sometimes', Rule::enum(StudentStatus::class)],
            'family_contact_name' => ['nullable', 'string', 'max:255'],
            'family_contact_phone' => ['nullable', 'string', 'max:50'],
            'family_contact_email' => ['nullable', 'email', 'max:255'],
            'pedagogical_notes' => ['nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 4: `UpdateStudentRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Enums\StudentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('student'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'group_id' => [
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where(
                    fn ($query) => $query->where('school_id', $this->user()->school_id)
                ),
            ],
            'status' => ['sometimes', Rule::enum(StudentStatus::class)],
            'family_contact_name' => ['nullable', 'string', 'max:255'],
            'family_contact_phone' => ['nullable', 'string', 'max:50'],
            'family_contact_email' => ['nullable', 'email', 'max:255'],
            'pedagogical_notes' => ['nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add api/app/Http/Requests/StoreGroupRequest.php api/app/Http/Requests/UpdateGroupRequest.php api/app/Http/Requests/StoreStudentRequest.php api/app/Http/Requests/UpdateStudentRequest.php
git commit -m "feat: add form requests for group and student CRUD"
```

---

## Task 6: `GroupController` + routes + feature tests

**Files:**
- Modify: `api/app/Http/Controllers/Controller.php`
- Create: `api/app/Http/Controllers/GroupController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/GroupEndpointsTest.php`

**Interfaces:**
- Consumes: `StoreGroupRequest`, `UpdateGroupRequest` (Task 5), `GroupResource` (Task 4), `GroupPolicy` (Task 2).
- Produces: `GET/POST /api/groups`, `GET/PUT/PATCH/DELETE /api/groups/{group}`. Response envelope for `index` is `{"data": [...]}`, for single-resource is `{"data": {...}}` (default Laravel `JsonResource` wrapping) — this is the shape Task 10's frontend `groupsApi.ts` expects.

- [ ] **Step 1: Add `AuthorizesRequests` to the base controller**

Replace `api/app/Http/Controllers/Controller.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\Group;
use App\Models\School;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets a director list, create, update and delete groups in their school', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $create = $this->postJson('/api/groups', ['name' => '3° A', 'level' => 'Primaria', 'year' => '2026']);
    $create->assertCreated()->assertJsonPath('data.name', '3° A');
    $groupId = $create->json('data.id');

    $this->getJson('/api/groups')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson("/api/groups/{$groupId}", ['name' => '3° B'])
        ->assertOk()
        ->assertJsonPath('data.name', '3° B');

    $this->deleteJson("/api/groups/{$groupId}")->assertNoContent();
    $this->getJson('/api/groups')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects a teacher trying to create, update or delete a group', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $group = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    Sanctum::actingAs($teacher);

    $this->postJson('/api/groups', ['name' => '3° A'])->assertForbidden();
    $this->putJson("/api/groups/{$group->id}", ['name' => 'x'])->assertForbidden();
    $this->deleteJson("/api/groups/{$group->id}")->assertForbidden();
});

it('lists only the groups a teacher leads, but all groups for school-wide roles', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $director = User::factory()->forSchool($school)->director()->create();

    Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    Group::factory()->create(['school_id' => $school->id]);

    Sanctum::actingAs($teacher);
    $this->getJson('/api/groups')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($director);
    $this->getJson('/api/groups')->assertOk()->assertJsonCount(2, 'data');
});

it('never exposes or modifies another school\'s group', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $groupB = Group::factory()->create(['school_id' => $schoolB->id]);

    Sanctum::actingAs($directorA);

    $this->getJson("/api/groups/{$groupB->id}")->assertNotFound();
    $this->putJson("/api/groups/{$groupB->id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/groups/{$groupB->id}")->assertNotFound();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd api && ./vendor/bin/sail test --filter=GroupEndpointsTest`
Expected: FAIL — route `groups` not defined (404 instead of expected statuses).

- [ ] **Step 4: Write `GroupController`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Models\Group;

class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Group::query()->with('teacher');

        if (! $user->hasAnyRole(Role::schoolWideValues())) {
            $query->where('teacher_id', $user->id);
        }

        return GroupResource::collection($query->latest()->get());
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $group = Group::create($request->validated());

        return (new GroupResource($group->load('teacher')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Group $group): GroupResource
    {
        $this->authorize('view', $group);

        return new GroupResource($group->load('teacher'));
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $group->update($request->validated());

        return new GroupResource($group->load('teacher'));
    }

    public function destroy(Group $group): JsonResponse
    {
        $this->authorize('delete', $group);

        $group->delete();

        return response()->json(null, 204);
    }
}
```

- [ ] **Step 5: Register the routes**

In `api/routes/api.php`, add the import and route:

```php
use App\Http\Controllers\GroupController;
```

Inside the existing `Route::middleware('auth:sanctum')->group(...)` closure, after the `/me` route:

```php
    Route::apiResource('groups', GroupController::class);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd api && ./vendor/bin/sail test --filter=GroupEndpointsTest`
Expected: PASS (4 tests)

- [ ] **Step 7: Run the full backend suite to check for regressions**

Run: `cd api && ./vendor/bin/sail test`
Expected: PASS (all tests, including the earlier Group/Student policy and resource tests)

- [ ] **Step 8: Commit**

```bash
git add api/app/Http/Controllers/Controller.php api/app/Http/Controllers/GroupController.php api/routes/api.php api/tests/Feature/GroupEndpointsTest.php
git commit -m "feat: add group CRUD endpoints"
```

---

## Task 7: `StudentController` + routes + feature tests

**Files:**
- Create: `api/app/Http/Controllers/StudentController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/StudentEndpointsTest.php`

**Interfaces:**
- Consumes: `StoreStudentRequest`, `UpdateStudentRequest` (Task 5), `StudentResource` (Task 4), `StudentPolicy` (Task 3).
- Produces: `GET/POST /api/students`, `GET/PUT/PATCH/DELETE /api/students/{student}`, same envelope shape as groups.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets a director list, create, update and delete students in their school', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    Sanctum::actingAs($director);

    $create = $this->postJson('/api/students', ['first_name' => 'Ana', 'last_name' => 'Gómez']);
    $create->assertCreated()->assertJsonPath('data.full_name', 'Ana Gómez')
        ->assertJsonPath('data.status', 'active');
    $studentId = $create->json('data.id');

    $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson("/api/students/{$studentId}", [
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
        'status' => 'inactive',
    ])->assertOk()->assertJsonPath('data.status', 'inactive');

    $this->deleteJson("/api/students/{$studentId}")->assertNoContent();
    $this->getJson('/api/students')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects a psychopedagogue trying to create or update a student', function () {
    $school = School::factory()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $student = Student::factory()->create(['school_id' => $school->id]);
    Sanctum::actingAs($psychopedagogue);

    $this->postJson('/api/students', ['first_name' => 'Ana', 'last_name' => 'Gómez'])->assertForbidden();
    $this->putJson("/api/students/{$student->id}", ['first_name' => 'x', 'last_name' => 'y'])->assertForbidden();
});

it('lists only students in groups a teacher leads, but all students for school-wide roles', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();
    $psychopedagogue = User::factory()->forSchool($school)->psychopedagogue()->create();
    $ownGroup = Group::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
    $otherGroup = Group::factory()->create(['school_id' => $school->id]);

    Student::factory()->create(['school_id' => $school->id, 'group_id' => $ownGroup->id]);
    Student::factory()->create(['school_id' => $school->id, 'group_id' => $otherGroup->id]);

    Sanctum::actingAs($teacher);
    $this->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($psychopedagogue);
    $this->getJson('/api/students')->assertOk()->assertJsonCount(2, 'data');
});

it('validates required student fields', function () {
    $school = School::factory()->create();
    Sanctum::actingAs(User::factory()->forSchool($school)->director()->create());

    $this->postJson('/api/students', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['first_name', 'last_name']);
});

it('never exposes or modifies another school\'s student', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $directorA = User::factory()->forSchool($schoolA)->director()->create();
    $studentB = Student::factory()->create(['school_id' => $schoolB->id]);

    Sanctum::actingAs($directorA);

    $this->getJson("/api/students/{$studentB->id}")->assertNotFound();
    $this->putJson("/api/students/{$studentB->id}", ['first_name' => 'x', 'last_name' => 'y'])->assertNotFound();
    $this->deleteJson("/api/students/{$studentB->id}")->assertNotFound();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd api && ./vendor/bin/sail test --filter=StudentEndpointsTest`
Expected: FAIL — route `students` not defined.

- [ ] **Step 3: Write `StudentController`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Student::query()->with('group');

        if (! $user->hasAnyRole(Role::schoolWideValues())) {
            $query->whereHas('group', fn ($q) => $q->where('teacher_id', $user->id));
        }

        return StudentResource::collection($query->latest()->get());
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = Student::create($request->validated());

        return (new StudentResource($student->load('group')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Student $student): StudentResource
    {
        $this->authorize('view', $student);

        return new StudentResource($student->load('group'));
    }

    public function update(UpdateStudentRequest $request, Student $student): StudentResource
    {
        $student->update($request->validated());

        return new StudentResource($student->load('group'));
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->authorize('delete', $student);

        $student->delete();

        return response()->json(null, 204);
    }
}
```

- [ ] **Step 4: Register the route**

In `api/routes/api.php`, add the import:

```php
use App\Http\Controllers\StudentController;
```

Add below the `groups` route:

```php
    Route::apiResource('students', StudentController::class);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd api && ./vendor/bin/sail test --filter=StudentEndpointsTest`
Expected: PASS (5 tests)

- [ ] **Step 6: Run the full backend suite**

Run: `cd api && ./vendor/bin/sail test`
Expected: PASS (all tests)

- [ ] **Step 7: Run Pint**

Run: `cd api && ./vendor/bin/sail bin pint --test`
Expected: no style violations. If it reports fixable files, run `./vendor/bin/sail bin pint` and re-check the diff before committing.

- [ ] **Step 8: Commit**

```bash
git add api/app/Http/Controllers/StudentController.php api/routes/api.php api/tests/Feature/StudentEndpointsTest.php
git commit -m "feat: add student CRUD endpoints"
```

---

## Task 8: Frontend types + `Select`/`Textarea` UI primitives

**Files:**
- Modify: `web/src/types.ts`
- Create: `web/src/components/ui/select.tsx`
- Create: `web/src/components/ui/textarea.tsx`

**Interfaces:**
- Produces: `Group`, `Student`, `StudentStatus` types and `studentStatusLabels` map in `types.ts`, consumed by Tasks 10–13. `Select`/`Textarea` components consumed by Task 13's `StudentFormPage`.

- [ ] **Step 1: Add types**

Append to `web/src/types.ts`:

```ts
export type StudentStatus = "active" | "inactive"

export const studentStatusLabels: Record<StudentStatus, string> = {
  active: "Activo",
  inactive: "Inactivo",
}

export interface Group {
  id: number
  name: string
  level: string | null
  year: string | null
  teacher_id: number | null
  teacher: { id: number; name: string } | null
}

export interface Student {
  id: number
  first_name: string
  last_name: string
  full_name: string
  birth_date: string | null
  status: StudentStatus
  family_contact_name: string | null
  family_contact_phone: string | null
  family_contact_email: string | null
  pedagogical_notes: string | null
  group_id: number | null
  group: { id: number; name: string } | null
}
```

- [ ] **Step 2: `Select` primitive**

```tsx
import * as React from "react"

import { cn } from "@/lib/utils"

function Select({ className, ...props }: React.ComponentProps<"select">) {
  return (
    <select
      data-slot="select"
      className={cn(
        "h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 md:text-sm dark:bg-input/30",
        className
      )}
      {...props}
    />
  )
}

export { Select }
```

- [ ] **Step 3: `Textarea` primitive**

```tsx
import * as React from "react"

import { cn } from "@/lib/utils"

function Textarea({ className, ...props }: React.ComponentProps<"textarea">) {
  return (
    <textarea
      data-slot="textarea"
      className={cn(
        "flex min-h-16 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
        className
      )}
      {...props}
    />
  )
}

export { Textarea }
```

- [ ] **Step 4: Verify with typecheck**

Run: `cd web && npm run typecheck`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add web/src/types.ts web/src/components/ui/select.tsx web/src/components/ui/textarea.tsx
git commit -m "feat: add group/student types and Select/Textarea primitives"
```

---

## Task 9: `AppLayout` + `ProtectedLayout` + wire into `App.tsx`

**Files:**
- Create: `web/src/components/AppLayout.tsx`
- Create: `web/src/components/ProtectedLayout.tsx`
- Test: `web/src/components/AppLayout.test.tsx`
- Modify: `web/src/App.tsx`
- Modify: `web/src/pages/DashboardPage.tsx`

**Interfaces:**
- Produces: `ProtectedLayout` — a component combining `ProtectedRoute` + `AppLayout`, used by Tasks 10–13 to wrap every authenticated route in `App.tsx`.

- [ ] **Step 1: Write the failing test**

```tsx
// web/src/components/AppLayout.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { AppLayout } from "./AppLayout"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"

function renderLayout(logout = vi.fn()) {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: ["director"] },
    loading: false,
    login: vi.fn(),
    logout,
  }

  render(
    <AuthContext value={value}>
      <MemoryRouter>
        <AppLayout>
          <p>contenido</p>
        </AppLayout>
      </MemoryRouter>
    </AuthContext>,
  )

  return { logout }
}

describe("AppLayout", () => {
  it("shows navigation links and the wrapped content", () => {
    renderLayout()

    expect(screen.getByRole("link", { name: "Clases" })).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Alumnos" })).toBeInTheDocument()
    expect(screen.getByText("contenido")).toBeInTheDocument()
  })

  it("calls logout when the button is clicked", async () => {
    const { logout } = renderLayout()

    await userEvent.click(screen.getByRole("button", { name: /cerrar sesión/i }))

    expect(logout).toHaveBeenCalled()
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd web && npm run test -- --run AppLayout`
Expected: FAIL — module `./AppLayout` not found.

- [ ] **Step 3: Write `AppLayout`**

```tsx
import type { ReactNode } from "react"
import { NavLink } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Button } from "@/components/ui/button"

const navItems = [
  { to: "/", label: "Inicio" },
  { to: "/clases", label: "Clases" },
  { to: "/alumnos", label: "Alumnos" },
]

export function AppLayout({ children }: { children: ReactNode }) {
  const { logout } = useAuth()

  return (
    <div className="min-h-svh">
      <header className="border-b">
        <div className="mx-auto flex max-w-5xl items-center justify-between p-4">
          <nav className="flex gap-4 text-sm">
            {navItems.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === "/"}
                className={({ isActive }) =>
                  isActive ? "font-semibold" : "text-muted-foreground hover:text-foreground"
                }
              >
                {item.label}
              </NavLink>
            ))}
          </nav>
          <Button variant="outline" size="sm" onClick={() => logout()}>
            Cerrar sesión
          </Button>
        </div>
      </header>
      <main className="mx-auto max-w-5xl p-6">{children}</main>
    </div>
  )
}
```

- [ ] **Step 4: Write `ProtectedLayout`**

```tsx
import type { ReactNode } from "react"
import { ProtectedRoute } from "@/components/ProtectedRoute"
import { AppLayout } from "@/components/AppLayout"

export function ProtectedLayout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute>
      <AppLayout>{children}</AppLayout>
    </ProtectedRoute>
  )
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd web && npm run test -- --run AppLayout`
Expected: PASS (2 tests)

- [ ] **Step 6: Wire `ProtectedLayout` into `App.tsx` and drop the now-duplicate logout button from `DashboardPage`**

Replace `web/src/App.tsx`:

```tsx
import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom"
import { AuthProvider } from "@/features/auth/AuthProvider"
import { ProtectedLayout } from "@/components/ProtectedLayout"
import { LoginPage } from "@/pages/LoginPage"
import { DashboardPage } from "@/pages/DashboardPage"

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route
            path="/"
            element={
              <ProtectedLayout>
                <DashboardPage />
              </ProtectedLayout>
            }
          />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
```

In `web/src/pages/DashboardPage.tsx`, remove the now-redundant header (logout lives in `AppLayout` now). Replace the file's `return` block:

```tsx
export function DashboardPage() {
  const { user } = useAuth()

  if (!user) return null

  return (
    <div className="grid gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Hola, {user.name}</h1>
        <p className="text-muted-foreground">
          {user.school?.name ?? "Sin escuela asignada"}
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Tu cuenta</CardTitle>
          <CardDescription>
            Datos resueltos por el backend (Sanctum + Spatie roles).
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-2 text-sm">
          <Row label="Email" value={user.email} />
          <Row label="Escuela" value={user.school?.name ?? "—"} />
          <Row
            label="Roles"
            value={user.roles.map((role) => roleLabels[role]).join(", ") || "—"}
          />
        </CardContent>
      </Card>
    </div>
  )
}
```

Remove the now-unused `Button` import and the `logout` destructure from `useAuth()` at the top of the file (keep the `Row` helper and other imports as-is).

- [ ] **Step 7: Run the full frontend suite**

Run: `cd web && npm run test -- --run`
Expected: PASS (all tests, including `LoginPage` and `AppLayout`)

- [ ] **Step 8: Commit**

```bash
git add web/src/components/AppLayout.tsx web/src/components/ProtectedLayout.tsx web/src/components/AppLayout.test.tsx web/src/App.tsx web/src/pages/DashboardPage.tsx
git commit -m "feat: add app navigation shell (AppLayout)"
```

---

## Task 10: `groupsApi.ts` + `GroupsListPage`

**Files:**
- Create: `web/src/features/groups/groupsApi.ts`
- Create: `web/src/features/groups/GroupsListPage.tsx`
- Test: `web/src/features/groups/GroupsListPage.test.tsx`
- Modify: `web/src/App.tsx`

**Interfaces:**
- Consumes: `Group` type (Task 8), `ProtectedLayout` (Task 9), `GET /api/groups` (Task 6).
- Produces: `groupsApi.fetchGroups(): Promise<Group[]>`, `groupsApi.fetchGroup(id): Promise<Group>`, `groupsApi.createGroup(input): Promise<Group>`, `groupsApi.updateGroup(id, input): Promise<Group>`, `groupsApi.deleteGroup(id): Promise<void>` — `createGroup`/`updateGroup` consumed by Task 11's `GroupFormPage`.

- [ ] **Step 1: Write `groupsApi.ts`**

```ts
import { api } from "@/lib/api"
import type { Group } from "@/types"

export interface GroupInput {
  name: string
  level?: string
  year?: string
  teacher_id?: number | null
}

export async function fetchGroups(): Promise<Group[]> {
  const { data } = await api.get<{ data: Group[] }>("/api/groups")
  return data.data
}

export async function fetchGroup(id: number): Promise<Group> {
  const { data } = await api.get<{ data: Group }>(`/api/groups/${id}`)
  return data.data
}

export async function createGroup(input: GroupInput): Promise<Group> {
  const { data } = await api.post<{ data: Group }>("/api/groups", input)
  return data.data
}

export async function updateGroup(id: number, input: GroupInput): Promise<Group> {
  const { data } = await api.put<{ data: Group }>(`/api/groups/${id}`, input)
  return data.data
}

export async function deleteGroup(id: number): Promise<void> {
  await api.delete(`/api/groups/${id}`)
}
```

- [ ] **Step 2: Write the failing test**

```tsx
// web/src/features/groups/GroupsListPage.test.tsx
import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { GroupsListPage } from "./GroupsListPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as groupsApi from "./groupsApi"

function renderList(role: "director" | "teacher") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter>
        <GroupsListPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("GroupsListPage", () => {
  it("renders groups returned by the API and shows the create link for a director", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      { id: 1, name: "3° A", level: "Primaria", year: "2026", teacher_id: null, teacher: null },
    ])

    renderList("director")

    expect(await screen.findByText("3° A")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nueva clase/i })).toBeInTheDocument()
  })

  it("hides the create link for a teacher", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderList("teacher")

    expect(await screen.findByText(/todavía no hay clases/i)).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: /nueva clase/i })).not.toBeInTheDocument()
  })
})
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd web && npm run test -- --run GroupsListPage`
Expected: FAIL — module `./GroupsListPage` not found.

- [ ] **Step 4: Write `GroupsListPage`**

```tsx
import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Button } from "@/components/ui/button"
import * as groupsApi from "./groupsApi"
import type { Group } from "@/types"

export function GroupsListPage() {
  const { user } = useAuth()
  const isDirector = user?.roles.includes("director") ?? false
  const [groups, setGroups] = useState<Group[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    groupsApi
      .fetchGroups()
      .then((data) => {
        if (active) setGroups(data)
      })
      .catch(() => {
        if (active) setError("No pudimos cargar las clases.")
      })
    return () => {
      active = false
    }
  }, [])

  return (
    <div className="grid gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Clases</h1>
        {isDirector && (
          <Button asChild>
            <Link to="/clases/nueva">Nueva clase</Link>
          </Button>
        )}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!groups && !error && <p className="text-muted-foreground">Cargando…</p>}
      {groups && groups.length === 0 && (
        <p className="text-muted-foreground">Todavía no hay clases cargadas.</p>
      )}

      {groups && groups.length > 0 && (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b text-left text-muted-foreground">
              <th className="py-2 pr-4">Nombre</th>
              <th className="py-2 pr-4">Nivel</th>
              <th className="py-2 pr-4">Año</th>
              <th className="py-2 pr-4">Docente a cargo</th>
              {isDirector && <th className="py-2" />}
            </tr>
          </thead>
          <tbody>
            {groups.map((group) => (
              <tr key={group.id} className="border-b last:border-b-0">
                <td className="py-2 pr-4">{group.name}</td>
                <td className="py-2 pr-4">{group.level ?? "—"}</td>
                <td className="py-2 pr-4">{group.year ?? "—"}</td>
                <td className="py-2 pr-4">{group.teacher?.name ?? "—"}</td>
                {isDirector && (
                  <td className="py-2 text-right">
                    <Link
                      className="text-primary underline-offset-4 hover:underline"
                      to={`/clases/${group.id}`}
                    >
                      Editar
                    </Link>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
```

- [ ] **Step 5: Add the route**

In `web/src/App.tsx`, add the import:

```tsx
import { GroupsListPage } from "@/features/groups/GroupsListPage"
```

Add the route inside `<Routes>`, after the `/` route:

```tsx
          <Route
            path="/clases"
            element={
              <ProtectedLayout>
                <GroupsListPage />
              </ProtectedLayout>
            }
          />
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd web && npm run test -- --run GroupsListPage`
Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add web/src/features/groups/groupsApi.ts web/src/features/groups/GroupsListPage.tsx web/src/features/groups/GroupsListPage.test.tsx web/src/App.tsx
git commit -m "feat: add groups list page"
```

---

## Task 11: `GroupFormPage` (create + edit)

**Files:**
- Create: `web/src/features/groups/GroupFormPage.tsx`
- Test: `web/src/features/groups/GroupFormPage.test.tsx`
- Modify: `web/src/App.tsx`

**Interfaces:**
- Consumes: `groupsApi.fetchGroup`, `groupsApi.createGroup`, `groupsApi.updateGroup` (Task 10).

- [ ] **Step 1: Write the failing test**

```tsx
// web/src/features/groups/GroupFormPage.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { GroupFormPage } from "./GroupFormPage"
import * as groupsApi from "./groupsApi"

describe("GroupFormPage", () => {
  it("shows a validation error and does not submit when name is empty", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup")

    render(
      <MemoryRouter initialEntries={["/clases/nueva"]}>
        <Routes>
          <Route path="/clases/nueva" element={<GroupFormPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createGroup).not.toHaveBeenCalled()
  })

  it("calls createGroup with the entered values on a valid submit", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({
      id: 1,
      name: "3° A",
      level: "",
      year: "",
      teacher_id: null,
      teacher: null,
    })

    render(
      <MemoryRouter initialEntries={["/clases/nueva"]}>
        <Routes>
          <Route path="/clases/nueva" element={<GroupFormPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith({ name: "3° A", level: "", year: "" })
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd web && npm run test -- --run GroupFormPage`
Expected: FAIL — module `./GroupFormPage` not found.

- [ ] **Step 3: Write `GroupFormPage`**

```tsx
import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import * as groupsApi from "./groupsApi"

const groupSchema = z.object({
  name: z.string().min(1, "Ingresá un nombre"),
  level: z.string().optional(),
  year: z.string().optional(),
})

type GroupValues = z.infer<typeof groupSchema>

export function GroupFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<GroupValues>({
    resolver: zodResolver(groupSchema),
    defaultValues: { name: "", level: "", year: "" },
  })

  useEffect(() => {
    if (!id) return
    groupsApi.fetchGroup(Number(id)).then((group) => {
      reset({ name: group.name, level: group.level ?? "", year: group.year ?? "" })
    })
  }, [id, reset])

  async function onSubmit(values: GroupValues) {
    setFormError(null)
    try {
      if (isEdit) {
        await groupsApi.updateGroup(Number(id), values)
      } else {
        await groupsApi.createGroup(values)
      }
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos guardar la clase.")
    }
  }

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>{isEdit ? "Editar clase" : "Nueva clase"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="name">Nombre</Label>
            <Input id="name" {...register("name")} />
            {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="level">Nivel</Label>
            <Input id="level" {...register("level")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="year">Año</Label>
            <Input id="year" {...register("year")} />
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Guardando…" : "Guardar"}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
```

- [ ] **Step 4: Add the routes**

In `web/src/App.tsx`, add the import:

```tsx
import { GroupFormPage } from "@/features/groups/GroupFormPage"
```

Add two routes after `/clases`:

```tsx
          <Route
            path="/clases/nueva"
            element={
              <ProtectedLayout>
                <GroupFormPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/clases/:id"
            element={
              <ProtectedLayout>
                <GroupFormPage />
              </ProtectedLayout>
            }
          />
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd web && npm run test -- --run GroupFormPage`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add web/src/features/groups/GroupFormPage.tsx web/src/features/groups/GroupFormPage.test.tsx web/src/App.tsx
git commit -m "feat: add group create/edit form page"
```

---

## Task 12: `studentsApi.ts` + `StudentsListPage`

**Files:**
- Create: `web/src/features/students/studentsApi.ts`
- Create: `web/src/features/students/StudentsListPage.tsx`
- Test: `web/src/features/students/StudentsListPage.test.tsx`
- Modify: `web/src/App.tsx`

**Interfaces:**
- Consumes: `Student`, `studentStatusLabels` (Task 8), `GET /api/students` (Task 7).
- Produces: `studentsApi.fetchStudents/fetchStudent/createStudent/updateStudent/deleteStudent`, consumed by Task 13.

- [ ] **Step 1: Write `studentsApi.ts`**

```ts
import { api } from "@/lib/api"
import type { Student, StudentStatus } from "@/types"

export interface StudentInput {
  first_name: string
  last_name: string
  birth_date?: string
  group_id?: number | null
  status?: StudentStatus
  family_contact_name?: string
  family_contact_phone?: string
  family_contact_email?: string
  pedagogical_notes?: string
}

export async function fetchStudents(): Promise<Student[]> {
  const { data } = await api.get<{ data: Student[] }>("/api/students")
  return data.data
}

export async function fetchStudent(id: number): Promise<Student> {
  const { data } = await api.get<{ data: Student }>(`/api/students/${id}`)
  return data.data
}

export async function createStudent(input: StudentInput): Promise<Student> {
  const { data } = await api.post<{ data: Student }>("/api/students", input)
  return data.data
}

export async function updateStudent(id: number, input: StudentInput): Promise<Student> {
  const { data } = await api.put<{ data: Student }>(`/api/students/${id}`, input)
  return data.data
}

export async function deleteStudent(id: number): Promise<void> {
  await api.delete(`/api/students/${id}`)
}
```

- [ ] **Step 2: Write the failing test**

```tsx
// web/src/features/students/StudentsListPage.test.tsx
import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { StudentsListPage } from "./StudentsListPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as studentsApi from "./studentsApi"

function renderList(role: "director" | "teacher") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter>
        <StudentsListPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("StudentsListPage", () => {
  it("renders students returned by the API and shows the create link for a director", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([
      {
        id: 1,
        first_name: "Ana",
        last_name: "Gómez",
        full_name: "Ana Gómez",
        birth_date: null,
        status: "active",
        family_contact_name: null,
        family_contact_phone: null,
        family_contact_email: null,
        pedagogical_notes: null,
        group_id: null,
        group: null,
      },
    ])

    renderList("director")

    expect(await screen.findByText("Ana Gómez")).toBeInTheDocument()
    expect(screen.getByText("Activo")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nuevo alumno/i })).toBeInTheDocument()
  })

  it("hides the create link for a teacher", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([])

    renderList("teacher")

    expect(await screen.findByText(/todavía no hay alumnos/i)).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: /nuevo alumno/i })).not.toBeInTheDocument()
  })
})
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd web && npm run test -- --run StudentsListPage`
Expected: FAIL — module `./StudentsListPage` not found.

- [ ] **Step 4: Write `StudentsListPage`**

```tsx
import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Button } from "@/components/ui/button"
import { studentStatusLabels } from "@/types"
import * as studentsApi from "./studentsApi"
import type { Student } from "@/types"

export function StudentsListPage() {
  const { user } = useAuth()
  const isDirector = user?.roles.includes("director") ?? false
  const [students, setStudents] = useState<Student[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    studentsApi
      .fetchStudents()
      .then((data) => {
        if (active) setStudents(data)
      })
      .catch(() => {
        if (active) setError("No pudimos cargar los alumnos.")
      })
    return () => {
      active = false
    }
  }, [])

  return (
    <div className="grid gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Alumnos</h1>
        {isDirector && (
          <Button asChild>
            <Link to="/alumnos/nuevo">Nuevo alumno</Link>
          </Button>
        )}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!students && !error && <p className="text-muted-foreground">Cargando…</p>}
      {students && students.length === 0 && (
        <p className="text-muted-foreground">Todavía no hay alumnos cargados.</p>
      )}

      {students && students.length > 0 && (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b text-left text-muted-foreground">
              <th className="py-2 pr-4">Nombre</th>
              <th className="py-2 pr-4">Clase</th>
              <th className="py-2 pr-4">Estado</th>
              {isDirector && <th className="py-2" />}
            </tr>
          </thead>
          <tbody>
            {students.map((student) => (
              <tr key={student.id} className="border-b last:border-b-0">
                <td className="py-2 pr-4">{student.full_name}</td>
                <td className="py-2 pr-4">{student.group?.name ?? "—"}</td>
                <td className="py-2 pr-4">{studentStatusLabels[student.status]}</td>
                {isDirector && (
                  <td className="py-2 text-right">
                    <Link
                      className="text-primary underline-offset-4 hover:underline"
                      to={`/alumnos/${student.id}`}
                    >
                      Editar
                    </Link>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
```

- [ ] **Step 5: Add the route**

In `web/src/App.tsx`, add the import:

```tsx
import { StudentsListPage } from "@/features/students/StudentsListPage"
```

Add the route after `/clases/:id`:

```tsx
          <Route
            path="/alumnos"
            element={
              <ProtectedLayout>
                <StudentsListPage />
              </ProtectedLayout>
            }
          />
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd web && npm run test -- --run StudentsListPage`
Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add web/src/features/students/studentsApi.ts web/src/features/students/StudentsListPage.tsx web/src/features/students/StudentsListPage.test.tsx web/src/App.tsx
git commit -m "feat: add students list page"
```

---

## Task 13: `StudentFormPage` (create + edit)

**Files:**
- Create: `web/src/features/students/StudentFormPage.tsx`
- Test: `web/src/features/students/StudentFormPage.test.tsx`
- Modify: `web/src/App.tsx`

**Interfaces:**
- Consumes: `studentsApi.fetchStudent/createStudent/updateStudent` (Task 12), `groupsApi.fetchGroups` (Task 10) for the class dropdown, `Select`/`Textarea` (Task 8).

- [ ] **Step 1: Write the failing test**

```tsx
// web/src/features/students/StudentFormPage.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { StudentFormPage } from "./StudentFormPage"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"

describe("StudentFormPage", () => {
  it("shows validation errors and does not submit when required fields are empty", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent")

    render(
      <MemoryRouter initialEntries={["/alumnos/nuevo"]}>
        <Routes>
          <Route path="/alumnos/nuevo" element={<StudentFormPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createStudent).not.toHaveBeenCalled()
  })

  it("calls createStudent with the entered values on a valid submit", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      { id: 1, name: "3° A", level: null, year: null, teacher_id: null, teacher: null },
    ])
    const createStudent = vi.spyOn(studentsApi, "createStudent").mockResolvedValue({
      id: 1,
      first_name: "Ana",
      last_name: "Gómez",
      full_name: "Ana Gómez",
      birth_date: null,
      status: "active",
      family_contact_name: null,
      family_contact_phone: null,
      family_contact_email: null,
      pedagogical_notes: null,
      group_id: null,
      group: null,
    })

    render(
      <MemoryRouter initialEntries={["/alumnos/nuevo"]}>
        <Routes>
          <Route path="/alumnos/nuevo" element={<StudentFormPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.type(screen.getByLabelText(/nombre/i), "Ana")
    await userEvent.type(screen.getByLabelText(/apellido/i), "Gómez")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createStudent).toHaveBeenCalledWith(
      expect.objectContaining({ first_name: "Ana", last_name: "Gómez", status: "active" }),
    )
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd web && npm run test -- --run StudentFormPage`
Expected: FAIL — module `./StudentFormPage` not found.

- [ ] **Step 3: Write `StudentFormPage`**

```tsx
import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { studentStatusLabels } from "@/types"
import type { Group } from "@/types"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"

const studentSchema = z.object({
  first_name: z.string().min(1, "Ingresá un nombre"),
  last_name: z.string().min(1, "Ingresá un apellido"),
  birth_date: z.string().optional(),
  group_id: z.string().optional(),
  status: z.enum(["active", "inactive"]),
  family_contact_name: z.string().optional(),
  family_contact_phone: z.string().optional(),
  family_contact_email: z.string().email("Email inválido").optional().or(z.literal("")),
  pedagogical_notes: z.string().optional(),
})

type StudentValues = z.infer<typeof studentSchema>

export function StudentFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [groups, setGroups] = useState<Group[]>([])

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<StudentValues>({
    resolver: zodResolver(studentSchema),
    defaultValues: {
      first_name: "",
      last_name: "",
      birth_date: "",
      group_id: "",
      status: "active",
      family_contact_name: "",
      family_contact_phone: "",
      family_contact_email: "",
      pedagogical_notes: "",
    },
  })

  useEffect(() => {
    groupsApi.fetchGroups().then(setGroups)
  }, [])

  useEffect(() => {
    if (!id) return
    studentsApi.fetchStudent(Number(id)).then((student) => {
      reset({
        first_name: student.first_name,
        last_name: student.last_name,
        birth_date: student.birth_date ?? "",
        group_id: student.group_id ? String(student.group_id) : "",
        status: student.status,
        family_contact_name: student.family_contact_name ?? "",
        family_contact_phone: student.family_contact_phone ?? "",
        family_contact_email: student.family_contact_email ?? "",
        pedagogical_notes: student.pedagogical_notes ?? "",
      })
    })
  }, [id, reset])

  async function onSubmit(values: StudentValues) {
    setFormError(null)
    try {
      const input = {
        ...values,
        group_id: values.group_id ? Number(values.group_id) : null,
      }
      if (isEdit) {
        await studentsApi.updateStudent(Number(id), input)
      } else {
        await studentsApi.createStudent(input)
      }
      navigate("/alumnos", { replace: true })
    } catch {
      setFormError("No pudimos guardar el alumno.")
    }
  }

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>{isEdit ? "Editar alumno" : "Nuevo alumno"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="first_name">Nombre</Label>
            <Input id="first_name" {...register("first_name")} />
            {errors.first_name && (
              <p className="text-sm text-destructive">{errors.first_name.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="last_name">Apellido</Label>
            <Input id="last_name" {...register("last_name")} />
            {errors.last_name && (
              <p className="text-sm text-destructive">{errors.last_name.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="birth_date">Fecha de nacimiento</Label>
            <Input id="birth_date" type="date" {...register("birth_date")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="group_id">Clase</Label>
            <Select id="group_id" {...register("group_id")}>
              <option value="">Sin clase asignada</option>
              {groups.map((group) => (
                <option key={group.id} value={group.id}>
                  {group.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="status">Estado</Label>
            <Select id="status" {...register("status")}>
              {Object.entries(studentStatusLabels).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="family_contact_name">Contacto de familia</Label>
            <Input id="family_contact_name" {...register("family_contact_name")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="family_contact_phone">Teléfono de contacto</Label>
            <Input id="family_contact_phone" {...register("family_contact_phone")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="family_contact_email">Email de contacto</Label>
            <Input id="family_contact_email" type="email" {...register("family_contact_email")} />
            {errors.family_contact_email && (
              <p className="text-sm text-destructive">{errors.family_contact_email.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="pedagogical_notes">Notas pedagógicas</Label>
            <Textarea id="pedagogical_notes" {...register("pedagogical_notes")} />
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Guardando…" : "Guardar"}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
```

- [ ] **Step 4: Add the routes**

In `web/src/App.tsx`, add the import:

```tsx
import { StudentFormPage } from "@/features/students/StudentFormPage"
```

Add two routes after `/alumnos`:

```tsx
          <Route
            path="/alumnos/nuevo"
            element={
              <ProtectedLayout>
                <StudentFormPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/alumnos/:id"
            element={
              <ProtectedLayout>
                <StudentFormPage />
              </ProtectedLayout>
            }
          />
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd web && npm run test -- --run StudentFormPage`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add web/src/features/students/StudentFormPage.tsx web/src/features/students/StudentFormPage.test.tsx web/src/App.tsx
git commit -m "feat: add student create/edit form page"
```

---

## Task 14: Full verification pass

**Files:** none (verification only).

- [ ] **Step 1: Backend — format, then full suite**

```bash
cd api
./vendor/bin/sail bin pint
./vendor/bin/sail test
```

Expected: Pint reports no changes needed (or only whitespace it just fixed — review and re-run `sail test` if it touched files); full suite green.

- [ ] **Step 2: Frontend — lint, typecheck, tests, build**

```bash
cd web
npm run lint
npm run typecheck
npm run test -- --run
npm run build
```

Expected: all four succeed with no errors.

- [ ] **Step 3: Manual smoke test in the browser**

With `sail up -d` and `npm run dev` running:
1. Log in as the director created via `sail artisan app:create-user`.
2. Go to `/clases`, create a class, edit it, confirm it appears updated in the list.
3. Go to `/alumnos`, create a student assigned to that class, with family contact info and a pedagogical note; confirm it appears in the list with the right class and status.
4. Log in as a teacher (create one via `app:create-user` or Tinker) and confirm the "Nueva clase"/"Nuevo alumno"/"Editar" links are hidden, and the list only shows their own class/students.

- [ ] **Step 4: Final commit if Pint/lint auto-fixed anything**

```bash
git status
# If pint or lint modified files:
git add -A
git commit -m "chore: apply formatting fixes"
```

---

> **Addendum (post-review):** the whole-branch review after Task 14 found the
> spec's "eliminar" for clases and the "docente a cargo" field were never
> wired into the frontend — the backend already supports both. Tasks 15-16
> close those two gaps.

## Task 15: Delete action on `GroupFormPage`

**Files:**
- Modify: `web/src/features/groups/GroupFormPage.tsx`
- Modify: `web/src/features/groups/GroupFormPage.test.tsx`

**Interfaces:**
- Consumes: `groupsApi.deleteGroup(id): Promise<void>` (already exists, unused until now).
- No new exports — this only adds a button and a handler to the existing component.

The backend `DELETE /api/groups/{group}` endpoint, its `GroupPolicy::delete` (director-only), and the `groupsApi.deleteGroup()` client function already exist and are tested — this task only adds the missing UI trigger.

- [ ] **Step 1: Write the failing tests**

Replace `web/src/features/groups/GroupFormPage.test.tsx` in full:

```tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { beforeEach, describe, expect, it, vi } from "vitest"
import { GroupFormPage } from "./GroupFormPage"
import * as groupsApi from "./groupsApi"

function renderCreate() {
  return render(
    <MemoryRouter initialEntries={["/clases/nueva"]}>
      <Routes>
        <Route path="/clases/nueva" element={<GroupFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

function renderEdit(id = "1") {
  return render(
    <MemoryRouter initialEntries={[`/clases/${id}`]}>
      <Routes>
        <Route path="/clases/:id" element={<GroupFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe("GroupFormPage", () => {
  it("shows a validation error and does not submit when name is empty", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup")

    renderCreate()

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createGroup).not.toHaveBeenCalled()
  })

  it("calls createGroup with the entered values on a valid submit", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({
      id: 1,
      name: "3° A",
      level: "",
      year: "",
      teacher_id: null,
      teacher: null,
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith({ name: "3° A", level: "", year: "" })
  })

  it("does not show a delete button when creating a new group", () => {
    renderCreate()

    expect(screen.queryByRole("button", { name: /eliminar clase/i })).not.toBeInTheDocument()
  })

  describe("editing an existing group", () => {
    beforeEach(() => {
      vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue({
        id: 1,
        name: "3° A",
        level: "Primaria",
        year: "2026",
        teacher_id: null,
        teacher: null,
      })
    })

    it("shows a delete button and calls deleteGroup after confirming", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup").mockResolvedValue(undefined)
      vi.stubGlobal("confirm", vi.fn(() => true))

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)

      expect(deleteGroup).toHaveBeenCalledWith(1)

      vi.unstubAllGlobals()
    })

    it("does not call deleteGroup when the confirmation is cancelled", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup")
      vi.stubGlobal("confirm", vi.fn(() => false))

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)

      expect(deleteGroup).not.toHaveBeenCalled()

      vi.unstubAllGlobals()
    })
  })
})
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd web && npm run test -- --run GroupFormPage`
Expected: FAIL — no "Eliminar clase" button exists yet.

- [ ] **Step 3: Add the delete action to `GroupFormPage`**

Replace `web/src/features/groups/GroupFormPage.tsx` in full:

```tsx
import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import * as groupsApi from "./groupsApi"

const groupSchema = z.object({
  name: z.string().min(1, "Ingresá un nombre"),
  level: z.string().optional(),
  year: z.string().optional(),
})

type GroupValues = z.infer<typeof groupSchema>

export function GroupFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<GroupValues>({
    resolver: zodResolver(groupSchema),
    defaultValues: { name: "", level: "", year: "" },
  })

  useEffect(() => {
    if (!id) return
    groupsApi.fetchGroup(Number(id)).then((group) => {
      reset({ name: group.name, level: group.level ?? "", year: group.year ?? "" })
    })
  }, [id, reset])

  async function onSubmit(values: GroupValues) {
    setFormError(null)
    try {
      if (isEdit) {
        await groupsApi.updateGroup(Number(id), values)
      } else {
        await groupsApi.createGroup(values)
      }
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos guardar la clase.")
    }
  }

  async function onDelete() {
    if (!id) return
    if (!window.confirm("¿Eliminar esta clase? Esta acción no se puede deshacer.")) return

    setFormError(null)
    setIsDeleting(true)
    try {
      await groupsApi.deleteGroup(Number(id))
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos eliminar la clase.")
      setIsDeleting(false)
    }
  }

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>{isEdit ? "Editar clase" : "Nueva clase"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="name">Nombre</Label>
            <Input id="name" {...register("name")} />
            {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="level">Nivel</Label>
            <Input id="level" {...register("level")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="year">Año</Label>
            <Input id="year" {...register("year")} />
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Guardando…" : "Guardar"}
            </Button>
            {isEdit && (
              <Button type="button" variant="destructive" disabled={isDeleting} onClick={onDelete}>
                {isDeleting ? "Eliminando…" : "Eliminar clase"}
              </Button>
            )}
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd web && npm run test -- --run GroupFormPage`
Expected: PASS (6 tests)

- [ ] **Step 5: Run the full frontend suite**

Run: `cd web && npm run test -- --run`
Expected: PASS (all tests)

- [ ] **Step 6: Commit**

```bash
git add web/src/features/groups/GroupFormPage.tsx web/src/features/groups/GroupFormPage.test.tsx
git commit -m "feat: add delete action to the class edit form"
```

---

## Task 16: Teacher assignment on `GroupFormPage`

**Files:**
- Create: `api/app/Http/Controllers/TeacherOptionsController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/TeacherOptionsTest.php`
- Modify: `web/src/features/groups/groupsApi.ts`
- Modify: `web/src/features/groups/GroupFormPage.tsx`
- Modify: `web/src/features/groups/GroupFormPage.test.tsx`

**Interfaces:**
- Produces: `GET /api/teachers` → `{"data": [{id, name}, ...]}`, director-only, scoped to the caller's school, ordered by name.
- Produces: `groupsApi.fetchTeachers(): Promise<Teacher[]>`, consumed by `GroupFormPage`.
- `GroupFormPage`'s submitted payload gains `teacher_id: number | null`, already accepted by `StoreGroupRequest`/`UpdateGroupRequest` (Task 5) and already displayed by `GroupsListPage` (Task 10) — this task is the last piece connecting an already-validated, already-displayed field to an actual input.

### Backend

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\School;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets a director list teachers in their own school, ordered by name', function () {
    $school = School::factory()->create();
    $director = User::factory()->forSchool($school)->director()->create();
    User::factory()->forSchool($school)->teacher()->create(['name' => 'Zoe Diaz']);
    User::factory()->forSchool($school)->teacher()->create(['name' => 'Ana Ruiz']);
    User::factory()->forSchool($school)->psychopedagogue()->create();

    Sanctum::actingAs($director);

    $this->getJson('/api/teachers')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Ana Ruiz')
        ->assertJsonPath('data.1.name', 'Zoe Diaz');
});

it('excludes teachers from other schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();
    $director = User::factory()->forSchool($schoolA)->director()->create();
    User::factory()->forSchool($schoolB)->teacher()->create();

    Sanctum::actingAs($director);

    $this->getJson('/api/teachers')->assertOk()->assertJsonCount(0, 'data');
});

it('forbids non-directors from listing teachers', function () {
    $school = School::factory()->create();
    $teacher = User::factory()->forSchool($school)->teacher()->create();

    Sanctum::actingAs($teacher);

    $this->getJson('/api/teachers')->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/teachers')->assertUnauthorized();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd api && ./vendor/bin/sail test --filter=TeacherOptionsTest`
Expected: FAIL — route not defined.

- [ ] **Step 3: Write `TeacherOptionsController`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lightweight, read-only directory of teachers in the caller's school, used
 * to populate the "docente a cargo" selector on GroupFormPage. Director-only
 * — the only place this is consumed today is the group create/edit form.
 */
class TeacherOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Role::Director->value), 403);

        $teachers = User::query()
            ->where('school_id', $request->user()->school_id)
            ->role(Role::Teacher->value)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $teachers]);
    }
}
```

- [ ] **Step 4: Register the route**

In `api/routes/api.php`, add the import:

```php
use App\Http\Controllers\TeacherOptionsController;
```

Add inside the `auth:sanctum` group, after the `/me` route:

```php
    Route::get('/teachers', TeacherOptionsController::class);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd api && ./vendor/bin/sail test --filter=TeacherOptionsTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Run the full backend suite and Pint**

Run: `cd api && ./vendor/bin/sail test && ./vendor/bin/sail bin pint --test`
Expected: both clean.

- [ ] **Step 7: Commit**

```bash
git add api/app/Http/Controllers/TeacherOptionsController.php api/routes/api.php api/tests/Feature/TeacherOptionsTest.php
git commit -m "feat: add teacher directory endpoint for group assignment"
```

### Frontend

- [ ] **Step 8: Add `fetchTeachers` to `groupsApi.ts`**

Append to `web/src/features/groups/groupsApi.ts`:

```ts
export interface Teacher {
  id: number
  name: string
}

export async function fetchTeachers(): Promise<Teacher[]> {
  const { data } = await api.get<{ data: Teacher[] }>("/api/teachers")
  return data.data
}
```

- [ ] **Step 9: Write the failing tests**

Replace `web/src/features/groups/GroupFormPage.test.tsx` in full:

```tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { beforeEach, describe, expect, it, vi } from "vitest"
import { GroupFormPage } from "./GroupFormPage"
import * as groupsApi from "./groupsApi"

function renderCreate() {
  return render(
    <MemoryRouter initialEntries={["/clases/nueva"]}>
      <Routes>
        <Route path="/clases/nueva" element={<GroupFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

function renderEdit(id = "1") {
  return render(
    <MemoryRouter initialEntries={[`/clases/${id}`]}>
      <Routes>
        <Route path="/clases/:id" element={<GroupFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe("GroupFormPage", () => {
  beforeEach(() => {
    vi.spyOn(groupsApi, "fetchTeachers").mockResolvedValue([])
  })

  it("shows a validation error and does not submit when name is empty", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup")

    renderCreate()

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createGroup).not.toHaveBeenCalled()
  })

  it("calls createGroup with the entered values on a valid submit", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({
      id: 1,
      name: "3° A",
      level: "",
      year: "",
      teacher_id: null,
      teacher: null,
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith({
      name: "3° A",
      level: "",
      year: "",
      teacher_id: null,
    })
  })

  it("does not show a delete button when creating a new group", () => {
    renderCreate()

    expect(screen.queryByRole("button", { name: /eliminar clase/i })).not.toBeInTheDocument()
  })

  it("populates the teacher select from fetchTeachers and submits the chosen teacher_id as a number", async () => {
    vi.spyOn(groupsApi, "fetchTeachers").mockResolvedValue([
      { id: 5, name: "Ana Ruiz" },
      { id: 9, name: "Zoe Diaz" },
    ])
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({
      id: 1,
      name: "3° A",
      level: "",
      year: "",
      teacher_id: 5,
      teacher: { id: 5, name: "Ana Ruiz" },
    })

    renderCreate()

    expect(await screen.findByRole("option", { name: "Ana Ruiz" })).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.selectOptions(screen.getByLabelText(/docente a cargo/i), "5")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith({
      name: "3° A",
      level: "",
      year: "",
      teacher_id: 5,
    })
  })

  describe("editing an existing group", () => {
    beforeEach(() => {
      vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue({
        id: 1,
        name: "3° A",
        level: "Primaria",
        year: "2026",
        teacher_id: 5,
        teacher: { id: 5, name: "Ana Ruiz" },
      })
      vi.spyOn(groupsApi, "fetchTeachers").mockResolvedValue([
        { id: 5, name: "Ana Ruiz" },
        { id: 9, name: "Zoe Diaz" },
      ])
    })

    it("preselects the group's current teacher", async () => {
      renderEdit()

      const select = (await screen.findByLabelText(/docente a cargo/i)) as HTMLSelectElement
      expect(await screen.findByRole("option", { name: "Ana Ruiz" })).toBeInTheDocument()
      expect(select.value).toBe("5")
    })

    it("shows a delete button and calls deleteGroup after confirming", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup").mockResolvedValue(undefined)
      vi.stubGlobal("confirm", vi.fn(() => true))

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)

      expect(deleteGroup).toHaveBeenCalledWith(1)

      vi.unstubAllGlobals()
    })

    it("does not call deleteGroup when the confirmation is cancelled", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup")
      vi.stubGlobal("confirm", vi.fn(() => false))

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)

      expect(deleteGroup).not.toHaveBeenCalled()

      vi.unstubAllGlobals()
    })
  })
})
```

- [ ] **Step 10: Run the tests to verify they fail**

Run: `cd web && npm run test -- --run GroupFormPage`
Expected: FAIL — no "Docente a cargo" label/select exists yet, and `createGroup` is called without `teacher_id`.

- [ ] **Step 11: Add the teacher selector to `GroupFormPage`**

Replace `web/src/features/groups/GroupFormPage.tsx` in full:

```tsx
import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import * as groupsApi from "./groupsApi"
import type { Teacher } from "./groupsApi"

const groupSchema = z.object({
  name: z.string().min(1, "Ingresá un nombre"),
  level: z.string().optional(),
  year: z.string().optional(),
  teacher_id: z.string().optional(),
})

type GroupValues = z.infer<typeof groupSchema>

export function GroupFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)
  const [teachers, setTeachers] = useState<Teacher[]>([])

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<GroupValues>({
    resolver: zodResolver(groupSchema),
    defaultValues: { name: "", level: "", year: "", teacher_id: "" },
  })

  useEffect(() => {
    groupsApi.fetchTeachers().then(setTeachers)
  }, [])

  useEffect(() => {
    if (!id) return
    groupsApi.fetchGroup(Number(id)).then((group) => {
      reset({
        name: group.name,
        level: group.level ?? "",
        year: group.year ?? "",
        teacher_id: group.teacher_id ? String(group.teacher_id) : "",
      })
    })
  }, [id, reset])

  async function onSubmit(values: GroupValues) {
    setFormError(null)
    try {
      const input = {
        ...values,
        teacher_id: values.teacher_id ? Number(values.teacher_id) : null,
      }
      if (isEdit) {
        await groupsApi.updateGroup(Number(id), input)
      } else {
        await groupsApi.createGroup(input)
      }
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos guardar la clase.")
    }
  }

  async function onDelete() {
    if (!id) return
    if (!window.confirm("¿Eliminar esta clase? Esta acción no se puede deshacer.")) return

    setFormError(null)
    setIsDeleting(true)
    try {
      await groupsApi.deleteGroup(Number(id))
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos eliminar la clase.")
      setIsDeleting(false)
    }
  }

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>{isEdit ? "Editar clase" : "Nueva clase"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="name">Nombre</Label>
            <Input id="name" {...register("name")} />
            {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="level">Nivel</Label>
            <Input id="level" {...register("level")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="year">Año</Label>
            <Input id="year" {...register("year")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="teacher_id">Docente a cargo</Label>
            <Select id="teacher_id" {...register("teacher_id")}>
              <option value="">Sin docente asignado</option>
              {teachers.map((teacher) => (
                <option key={teacher.id} value={teacher.id}>
                  {teacher.name}
                </option>
              ))}
            </Select>
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Guardando…" : "Guardar"}
            </Button>
            {isEdit && (
              <Button type="button" variant="destructive" disabled={isDeleting} onClick={onDelete}>
                {isDeleting ? "Eliminando…" : "Eliminar clase"}
              </Button>
            )}
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
```

- [ ] **Step 12: Run the tests to verify they pass**

Run: `cd web && npm run test -- --run GroupFormPage`
Expected: PASS (8 tests)

- [ ] **Step 13: Run the full frontend suite, typecheck, and build**

Run: `cd web && npm run test -- --run && npm run typecheck && npm run build`
Expected: all clean.

- [ ] **Step 14: Commit**

```bash
git add web/src/features/groups/groupsApi.ts web/src/features/groups/GroupFormPage.tsx web/src/features/groups/GroupFormPage.test.tsx
git commit -m "feat: add teacher assignment selector to the class form"
```
