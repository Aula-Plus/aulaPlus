# User Management ("Usuarios") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a director-only "Usuarios" screen (plus its backend) that onboards teaching/pedagogical staff through an email-invitation flow, with a disable-not-delete lifecycle.

**Architecture:** New `/v1/users` CRUD + disable/enable/resend endpoints guarded by a `UserPolicy` (director-only, same-school enforced explicitly since `User` has no `SchoolScope`). A dedicated `user_invitations` table holds a hashed, single-use, expiring token; two **public** token-gated endpoints let the invited user set their password. Login is blocked for pending (no password) and disabled users. The React SPA adds a list page, a create/edit form, and a public acceptance page.

**Tech Stack:** Laravel 13 (PHP 8.3), PostgreSQL, Sanctum SPA cookie auth, Spatie laravel-permission, Pest. React 19 + Vite + TS + Tailwind v4 + shadcn/ui, axios, react-hook-form + Zod, Vitest.

## Global Constraints

- All code (identifiers, columns, comments) in **English**; all user-facing UI text in **Spanish** (CLAUDE.md → Language convention).
- Every mutating/admin endpoint requires authentication **and** an explicit authorization check in server code (security rule #3). No `USING(true)`/allow-all policy except the deliberately public, token-gated invitation routes (rule #4).
- `User` deliberately does **not** use `SchoolScope`; same-school isolation is enforced **explicitly** in every query and policy (CLAUDE.md → Multi-tenancy).
- All input validated in backend Form Requests regardless of frontend validation (rule #5).
- Never log the plaintext invitation token or any password (rule #2). The token is hashed at rest (`hash('sha256', …)`).
- Password strength for acceptance matches the artisan command: `Password::min(8)->letters()->numbers()`.
- Roles offered: the 3 current ones only — `teacher` / `director` / `psychopedagogue` (`App\Enums\Role`). Do not add `psychologist` / `at`.
- Backend: run `./vendor/bin/sail bin pint` + `./vendor/bin/sail test` before each commit. Frontend (from `web/`): `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`.
- API resource responses are `{ "data": … }`-wrapped; authenticated routes live under the `/v1` prefix inside the `auth:sanctum` group in `api/routes/api.php`.
- Frontend role gating is **UX only**, mirrors the backend policy, and is never the security boundary.

## File Structure

**Backend (`api/`)**
- Create: `database/migrations/2026_09_15_000001_add_invitation_columns_to_users_table.php` — password nullable + `disabled_at`.
- Create: `database/migrations/2026_09_15_000002_create_user_invitations_table.php`.
- Create: `app/Models/UserInvitation.php` — token model + `issueFor` / `findPending`.
- Modify: `app/Models/User.php` — `disabled_at` cast, `invitations()` relation, `isPending()` / `isDisabled()`.
- Create: `app/Policies/UserPolicy.php` — director-only, same-school (auto-discovered).
- Create: `app/Http/Resources/ManagedUserResource.php` — admin view with derived `status`.
- Create: `app/Http/Requests/StoreUserRequest.php`, `app/Http/Requests/UpdateUserRequest.php`, `app/Http/Requests/AcceptInvitationRequest.php`.
- Create: `app/Http/Controllers/UserController.php` — index/store/show/update/disable/enable/resendInvitation.
- Create: `app/Http/Controllers/InvitationController.php` — public show/accept.
- Create: `app/Notifications/UserInvitationNotification.php`.
- Modify: `config/app.php` — add `frontend_url`.
- Modify: `app/Http/Requests/Auth/LoginRequest.php` — block pending/disabled.
- Modify: `api/routes/api.php` — new routes.
- Create tests under `api/tests/Feature/` and `api/tests/Unit/`.

**Frontend (`web/`)**
- Modify: `src/types.ts` — `ManagedUser`, `UserStatus`, `userStatusLabels`.
- Create: `src/features/users/usersApi.ts`.
- Modify: `src/lib/permissions.ts` — `canManageUsers`.
- Modify: `src/components/nav-items.tsx` — "Usuarios" nav item.
- Create: `src/features/users/UsersListPage.tsx`.
- Create: `src/features/users/UserFormPage.tsx`.
- Create: `src/features/auth/AcceptInvitationPage.tsx`.
- Modify: `src/App.tsx` — `/usuarios` (protected) + `/aceptar-invitacion` (public) routes.
- Create tests alongside (`*.test.tsx`).

**Dependency order:** Tasks 1–2 (migration + model) land first. Then Backend-A (3–8) and Backend-B (9–11) can proceed in parallel, and Frontend (12–15) in parallel against the API contract documented here.

---

## Task 1: Schema migrations

**Files:**
- Create: `api/database/migrations/2026_09_15_000001_add_invitation_columns_to_users_table.php`
- Create: `api/database/migrations/2026_09_15_000002_create_user_invitations_table.php`
- Test: `api/tests/Feature/UserInvitationSchemaTest.php`

**Interfaces:**
- Produces: `users.password` nullable; `users.disabled_at` (nullable timestamp); `user_invitations` table (`id`, `user_id`, `token`, `expires_at`, `accepted_at`, timestamps).

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/UserInvitationSchemaTest.php

use Illuminate\Support\Facades\Schema;

it('has the invitation lifecycle columns and table', function () {
    expect(Schema::hasColumn('users', 'disabled_at'))->toBeTrue();
    expect(Schema::hasColumns('user_invitations', [
        'user_id', 'token', 'expires_at', 'accepted_at',
    ]))->toBeTrue();
});

it('allows a user with a null password', function () {
    $user = App\Models\User::factory()->create(['password' => null]);
    expect($user->fresh()->password)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=UserInvitationSchemaTest`
Expected: FAIL (`disabled_at` / `user_invitations` do not exist).

- [ ] **Step 3: Write the migrations**

```php
<?php
// api/database/migrations/2026_09_15_000001_add_invitation_columns_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // An invited user has no password until they accept the invitation.
            $table->string('password')->nullable()->change();
            // Deactivation ("dar de baja") — reversible; never a hard delete.
            $table->timestamp('disabled_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('disabled_at');
            $table->string('password')->nullable(false)->change();
        });
    }
};
```

```php
<?php
// api/database/migrations/2026_09_15_000002_create_user_invitations_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // SHA-256 hash of the plaintext token; the plaintext is only ever
            // in the email. Indexed for the by-token lookup on acceptance.
            $table->string('token')->index();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=UserInvitationSchemaTest`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/database/migrations api/tests/Feature/UserInvitationSchemaTest.php
git commit -m "feat(api): add invitation lifecycle schema (nullable password, disabled_at, user_invitations)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 2: `UserInvitation` model + `User` lifecycle helpers

**Files:**
- Create: `api/app/Models/UserInvitation.php`
- Modify: `api/app/Models/User.php`
- Test: `api/tests/Unit/UserInvitationTest.php`

**Interfaces:**
- Produces:
  - `UserInvitation::issueFor(User $user, int $days = 7): string` — deletes any pending invitation for the user, creates a new one, returns the **plaintext** token.
  - `UserInvitation::findPending(string $plain): ?UserInvitation` — matches hash, unaccepted, unexpired.
  - `UserInvitation::markAccepted(): void`
  - `User::isPending(): bool` (password is null), `User::isDisabled(): bool` (`disabled_at` not null), `User::invitations(): HasMany`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Unit/UserInvitationTest.php

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('issues a plaintext token stored only as a hash', function () {
    $user = User::factory()->create(['password' => null]);

    $plain = UserInvitation::issueFor($user);

    expect($plain)->toBeString()->and(strlen($plain))->toBeGreaterThan(30);
    $row = UserInvitation::firstWhere('user_id', $user->id);
    expect($row->token)->toBe(hash('sha256', $plain))
        ->and($row->token)->not->toBe($plain);
});

it('finds a pending invitation by plaintext and rejects expired/accepted ones', function () {
    $user = User::factory()->create(['password' => null]);
    $plain = UserInvitation::issueFor($user);

    expect(UserInvitation::findPending($plain)?->user_id)->toBe($user->id);
    expect(UserInvitation::findPending('wrong-token'))->toBeNull();

    UserInvitation::findPending($plain)->markAccepted();
    expect(UserInvitation::findPending($plain))->toBeNull();
});

it('replaces a prior pending invitation when re-issued', function () {
    $user = User::factory()->create(['password' => null]);
    $first = UserInvitation::issueFor($user);
    $second = UserInvitation::issueFor($user);

    expect(UserInvitation::findPending($first))->toBeNull();
    expect(UserInvitation::findPending($second)?->user_id)->toBe($user->id);
    expect(UserInvitation::where('user_id', $user->id)->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=UserInvitationTest`
Expected: FAIL (`UserInvitation` class not found).

- [ ] **Step 3: Create the model**

```php
<?php
// api/app/Models/UserInvitation.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use, expiring invitation for a not-yet-active user to set their
 * password. The plaintext token lives only in the email; the row stores its
 * SHA-256 hash so a database read never yields a usable token.
 */
class UserInvitation extends Model
{
    protected $fillable = ['user_id', 'token', 'expires_at', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a fresh invitation for the user, invalidating any prior pending
     * one, and return the plaintext token (the only place it ever exists
     * outside the email).
     */
    public static function issueFor(User $user, int $days = 7): string
    {
        static::query()
            ->where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->delete();

        $plain = Str::random(64);

        static::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addDays($days),
        ]);

        return $plain;
    }

    /**
     * Resolve a still-usable invitation from a plaintext token: hash matches,
     * not yet accepted, not expired.
     */
    public static function findPending(string $plain): ?self
    {
        return static::query()
            ->where('token', hash('sha256', $plain))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function markAccepted(): void
    {
        $this->update(['accepted_at' => now()]);
    }
}
```

- [ ] **Step 4: Add the `User` helpers and relation**

In `api/app/Models/User.php`, add `disabled_at` to the casts array:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'disabled_at' => 'datetime',
        ];
    }
```

Add these methods (e.g. after the `school()` relation):

```php
    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }

    /** Invited but has not set a password yet — cannot log in. */
    public function isPending(): bool
    {
        return $this->password === null;
    }

    /** Deactivated ("dado de baja") — cannot log in, record preserved. */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }
```

(`HasMany` is already imported in `User.php`.)

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=UserInvitationTest`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/app/Models/UserInvitation.php api/app/Models/User.php api/tests/Unit/UserInvitationTest.php
git commit -m "feat(api): UserInvitation model + User lifecycle helpers

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 3: `UserPolicy` (director-only, same-school)

**Files:**
- Create: `api/app/Policies/UserPolicy.php`
- Test: `api/tests/Feature/UserPolicyTest.php`

**Interfaces:**
- Produces abilities: `viewAny(User)`, `view(User,User)`, `create(User)`, `update(User,User)`, `disable(User,User)`, `enable(User,User)` — all require the actor to be `director`; the per-target ones also require same school. Auto-discovered by Laravel (`App\Policies\UserPolicy`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/UserPolicyTest.php

use App\Models\User;
use App\Policies\UserPolicy;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('lets a director manage users in their own school only', function () {
    $director = User::factory()->director()->create();
    $sameSchool = User::factory()->teacher()->create(['school_id' => $director->school_id]);
    $otherSchool = User::factory()->teacher()->create();

    $policy = new UserPolicy;

    expect($policy->viewAny($director))->toBeTrue();
    expect($policy->create($director))->toBeTrue();
    expect($policy->update($director, $sameSchool))->toBeTrue();
    expect($policy->update($director, $otherSchool))->toBeFalse();
});

it('denies non-directors', function () {
    $teacher = User::factory()->teacher()->create();
    $other = User::factory()->teacher()->create(['school_id' => $teacher->school_id]);

    $policy = new UserPolicy;

    expect($policy->viewAny($teacher))->toBeFalse();
    expect($policy->update($teacher, $other))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=UserPolicyTest`
Expected: FAIL (`UserPolicy` not found).

- [ ] **Step 3: Create the policy**

```php
<?php
// api/app/Policies/UserPolicy.php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Staff management is director-only. User rows are NOT constrained by
 * SchoolScope (see App\Models\User), so every per-target ability asserts the
 * same-school invariant explicitly — the frontend is never trusted for it.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function view(User $user, User $target): bool
    {
        return $this->manages($user, $target);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, User $target): bool
    {
        return $this->manages($user, $target);
    }

    public function disable(User $user, User $target): bool
    {
        return $this->manages($user, $target);
    }

    public function enable(User $user, User $target): bool
    {
        return $this->manages($user, $target);
    }

    protected function manages(User $user, User $target): bool
    {
        return $user->hasRole(Role::Director->value)
            && $user->school_id === $target->school_id;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=UserPolicyTest`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/app/Policies/UserPolicy.php api/tests/Feature/UserPolicyTest.php
git commit -m "feat(api): UserPolicy (director-only, same-school)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 4: `ManagedUserResource` + `GET /v1/users` (list)

**Files:**
- Create: `api/app/Http/Resources/ManagedUserResource.php`
- Create: `api/app/Http/Controllers/UserController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/UserIndexTest.php`

**Interfaces:**
- Consumes: `UserPolicy::viewAny`, `User::isPending`/`isDisabled`.
- Produces:
  - `ManagedUserResource` JSON: `{ id, name, email, roles: string[], status: "pending"|"active"|"disabled" }`.
  - `GET /api/v1/users?search=` → `{ data: ManagedUserResource[] }`, scoped to the caller's school. `UserController::index`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/UserIndexTest.php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('lists staff in the caller school with a derived status, director only', function () {
    $director = User::factory()->director()->create();
    $active = User::factory()->teacher()->create(['school_id' => $director->school_id]);
    $pending = User::factory()->teacher()->create(['school_id' => $director->school_id, 'password' => null]);
    User::factory()->teacher()->create(); // other school — must not appear

    actingAs($director)->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonCount(3, 'data') // director + active + pending
        ->assertJsonFragment(['id' => $pending->id, 'status' => 'pending'])
        ->assertJsonFragment(['id' => $active->id, 'status' => 'active']);
});

it('forbids non-directors', function () {
    $teacher = User::factory()->teacher()->create();
    actingAs($teacher)->getJson('/api/v1/users')->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=UserIndexTest`
Expected: FAIL (route/controller missing → 404).

- [ ] **Step 3: Create the resource**

```php
<?php
// api/app/Http/Resources/ManagedUserResource.php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member as seen on the director-only management screen. `status` is
 * derived from the lifecycle columns (never stored), so it cannot drift.
 *
 * @mixin User
 */
class ManagedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames()->values(),
            'status' => $this->status(),
        ];
    }

    private function status(): string
    {
        return match (true) {
            $this->isDisabled() => 'disabled',
            $this->isPending() => 'pending',
            default => 'active',
        };
    }
}
```

- [ ] **Step 4: Create the controller with `index`**

```php
<?php
// api/app/Http/Controllers/UserController.php

namespace App\Http\Controllers;

use App\Http\Resources\ManagedUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Director-only staff management. User rows are not SchoolScope-bound, so every
 * query is constrained to the caller's school explicitly and every action is
 * gated by UserPolicy.
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->where('school_id', $request->user()->school_id)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        return ManagedUserResource::collection($users);
    }
}
```

- [ ] **Step 5: Register the route**

In `api/routes/api.php`, add `use App\Http\Controllers\UserController;` with the other controller imports, then inside the `Route::prefix('v1')->group(...)` block add:

```php
        // Session: user management (docs/superpowers/specs/2026-09-15-user-
        // management-design.md). Director-only staff onboarding + lifecycle.
        Route::get('/users', [UserController::class, 'index']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=UserIndexTest`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/app/Http/Resources/ManagedUserResource.php api/app/Http/Controllers/UserController.php api/routes/api.php api/tests/Feature/UserIndexTest.php
git commit -m "feat(api): GET /v1/users list with derived status (director-only)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 5: `POST /v1/users` (create + invitation email)

**Files:**
- Create: `api/app/Http/Requests/StoreUserRequest.php`
- Create: `api/app/Notifications/UserInvitationNotification.php`
- Modify: `api/config/app.php`
- Modify: `api/app/Http/Controllers/UserController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/UserStoreTest.php`

**Interfaces:**
- Consumes: `UserPolicy::create`, `UserInvitation::issueFor`.
- Produces:
  - `POST /api/v1/users` body `{ name, email, role }` → 201 `{ data: ManagedUserResource }` with `status: "pending"`; creates the user (null password), assigns the role, issues an invitation, sends `UserInvitationNotification`.
  - `UserInvitationNotification(string $token)` — queued mail with a link to `{config('app.frontend_url')}/aceptar-invitacion?token=…`.
  - `config('app.frontend_url')`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/UserStoreTest.php

use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    $this->director = User::factory()->director()->create();
});

it('creates a pending user, assigns the role, and sends an invitation', function () {
    actingAs($this->director)->postJson('/api/v1/users', [
        'name' => 'Ana Docente',
        'email' => 'ana@example.com',
        'role' => 'teacher',
    ])->assertCreated()
      ->assertJsonPath('data.status', 'pending')
      ->assertJsonPath('data.roles.0', 'teacher');

    $created = User::firstWhere('email', 'ana@example.com');
    expect($created->school_id)->toBe($this->director->school_id);
    expect($created->password)->toBeNull();
    expect(UserInvitation::where('user_id', $created->id)->exists())->toBeTrue();
    Notification::assertSentTo($created, UserInvitationNotification::class);
});

it('rejects a duplicate email and an invalid role', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    actingAs($this->director)->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'taken@example.com', 'role' => 'teacher',
    ])->assertStatus(422)->assertJsonValidationErrorFor('email');

    actingAs($this->director)->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'new@example.com', 'role' => 'wizard',
    ])->assertStatus(422)->assertJsonValidationErrorFor('role');
});

it('forbids a non-director from creating users', function () {
    $teacher = User::factory()->teacher()->create();
    actingAs($teacher)->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'n@example.com', 'role' => 'teacher',
    ])->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=UserStoreTest`
Expected: FAIL (route/request/notification missing).

- [ ] **Step 3: Create the Form Request**

```php
<?php
// api/app/Http/Requests/StoreUserRequest.php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(Role::values())],
        ];
    }
}
```

- [ ] **Step 4: Add `frontend_url` to config**

In `api/config/app.php`, add inside the returned array (near `'url' => env('APP_URL', …)`):

```php
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
```

- [ ] **Step 5: Create the notification**

```php
<?php
// api/app/Notifications/UserInvitationNotification.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an invited staff member a single-use link to set their password.
 * The plaintext token is carried only in this message and is never logged.
 */
class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/aceptar-invitacion?token='.$this->token;

        return (new MailMessage)
            ->subject('Te invitaron a Aula+')
            ->greeting("Hola {$notifiable->name},")
            ->line('Fuiste invitado a Aula+. Creá tu contraseña para activar tu cuenta.')
            ->action('Activar mi cuenta', $url)
            ->line('El enlace vence en 7 días. Si no esperabas esta invitación, ignorá este correo.');
    }
}
```

- [ ] **Step 6: Add `store` to the controller**

Add these imports to `UserController.php`:

```php
use App\Http\Requests\StoreUserRequest;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Http\JsonResponse;
```

Add the method:

```php
    public function store(StoreUserRequest $request): JsonResponse
    {
        // User does not use BelongsToSchool, so school_id is set explicitly
        // from the authenticated director. Password stays null until the
        // invitee accepts.
        $user = User::create([
            'school_id' => $request->user()->school_id,
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => null,
        ]);

        $user->assignRole($request->validated('role'));

        $token = UserInvitation::issueFor($user);
        $user->notify(new UserInvitationNotification($token));

        return (new ManagedUserResource($user))->response()->setStatusCode(201);
    }
```

- [ ] **Step 7: Register the route**

In `api/routes/api.php`, below the `GET /users` line:

```php
        Route::post('/users', [UserController::class, 'store']);
```

- [ ] **Step 8: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=UserStoreTest`
Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/app/Http/Requests/StoreUserRequest.php api/app/Notifications/UserInvitationNotification.php api/config/app.php api/app/Http/Controllers/UserController.php api/routes/api.php api/tests/Feature/UserStoreTest.php
git commit -m "feat(api): POST /v1/users creates pending user + emails invitation

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 6: `GET /v1/users/{user}` + `PATCH /v1/users/{user}` (show + edit)

**Files:**
- Create: `api/app/Http/Requests/UpdateUserRequest.php`
- Modify: `api/app/Http/Controllers/UserController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/UserUpdateTest.php`

**Interfaces:**
- Consumes: `UserPolicy::view`/`update`.
- Produces:
  - `GET /api/v1/users/{user}` → `{ data: ManagedUserResource }`.
  - `PATCH /api/v1/users/{user}` body `{ name?, role? }` → `{ data: ManagedUserResource }`. Email is **not** accepted. `role` replaces the user's roles.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/UserUpdateTest.php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->director = User::factory()->director()->create();
});

it('updates name and role but never email', function () {
    $target = User::factory()->teacher()->create([
        'school_id' => $this->director->school_id,
        'email' => 'keep@example.com',
    ]);

    actingAs($this->director)->patchJson("/api/v1/users/{$target->id}", [
        'name' => 'Nombre Nuevo',
        'role' => 'psychopedagogue',
        'email' => 'hacker@example.com',
    ])->assertOk()
      ->assertJsonPath('data.name', 'Nombre Nuevo')
      ->assertJsonPath('data.roles.0', 'psychopedagogue');

    $target->refresh();
    expect($target->email)->toBe('keep@example.com');
    expect($target->hasRole('teacher'))->toBeFalse();
});

it('forbids editing a user from another school', function () {
    $other = User::factory()->teacher()->create();
    actingAs($this->director)->patchJson("/api/v1/users/{$other->id}", ['name' => 'X'])
        ->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=UserUpdateTest`
Expected: FAIL (routes missing).

- [ ] **Step 3: Create the Form Request**

```php
<?php
// api/app/Http/Requests/UpdateUserRequest.php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a staff member's name and/or role. Email is intentionally immutable
 * (identity is fixed at creation) and is not part of the ruleset, so any
 * `email` key in the payload is simply ignored.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->route('user');

        return $this->user()->can('update', $user);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'role' => ['sometimes', 'required', Rule::in(Role::values())],
        ];
    }
}
```

- [ ] **Step 4: Add `show` and `update` to the controller**

Add `use App\Http\Requests\UpdateUserRequest;` to the imports, then:

```php
    public function show(User $user): ManagedUserResource
    {
        $this->authorize('view', $user);

        return new ManagedUserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): ManagedUserResource
    {
        if ($request->has('name')) {
            $user->update(['name' => $request->validated('name')]);
        }

        if ($request->has('role')) {
            $user->syncRoles([$request->validated('role')]);
        }

        return new ManagedUserResource($user->refresh());
    }
```

- [ ] **Step 5: Register the routes**

In `api/routes/api.php`, below the `POST /users` line:

```php
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=UserUpdateTest`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/app/Http/Requests/UpdateUserRequest.php api/app/Http/Controllers/UserController.php api/routes/api.php api/tests/Feature/UserUpdateTest.php
git commit -m "feat(api): GET/PATCH /v1/users/{user} (email immutable)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 7: `POST /v1/users/{user}/disable` + `.../enable` (with guardrails)

**Files:**
- Modify: `api/app/Http/Controllers/UserController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/UserDisableTest.php`

**Interfaces:**
- Consumes: `UserPolicy::disable`/`enable`, `Role::Director`.
- Produces:
  - `POST /api/v1/users/{user}/disable` → `{ data: ManagedUserResource }` with `status: "disabled"`. 422 if the target is the actor themselves, or the last active director.
  - `POST /api/v1/users/{user}/enable` → `{ data: ManagedUserResource }` (clears `disabled_at`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/UserDisableTest.php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->director = User::factory()->director()->create();
});

it('disables and re-enables a user in the same school', function () {
    $target = User::factory()->teacher()->create(['school_id' => $this->director->school_id]);

    actingAs($this->director)->postJson("/api/v1/users/{$target->id}/disable")
        ->assertOk()->assertJsonPath('data.status', 'disabled');
    expect($target->refresh()->isDisabled())->toBeTrue();

    actingAs($this->director)->postJson("/api/v1/users/{$target->id}/enable")
        ->assertOk()->assertJsonPath('data.status', 'active');
    expect($target->refresh()->isDisabled())->toBeFalse();
});

it('refuses to disable yourself or the last active director', function () {
    // Only director in the school → cannot disable self.
    actingAs($this->director)->postJson("/api/v1/users/{$this->director->id}/disable")
        ->assertStatus(422);

    // A second director exists → still cannot disable self, but CAN disable the other.
    $second = User::factory()->director()->create(['school_id' => $this->director->school_id]);
    actingAs($this->director)->postJson("/api/v1/users/{$this->director->id}/disable")
        ->assertStatus(422);
    actingAs($this->director)->postJson("/api/v1/users/{$second->id}/disable")
        ->assertOk();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=UserDisableTest`
Expected: FAIL (routes missing).

- [ ] **Step 3: Add `disable`/`enable` to the controller**

Add these imports:

```php
use App\Enums\Role;
use Illuminate\Validation\ValidationException;
```

Add the methods:

```php
    public function disable(Request $request, User $user): ManagedUserResource
    {
        $this->authorize('disable', $user);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'No podés desactivar tu propia cuenta.',
            ]);
        }

        if ($this->isLastActiveDirector($user)) {
            throw ValidationException::withMessages([
                'user' => 'No podés desactivar al único director activo de la escuela.',
            ]);
        }

        $user->update(['disabled_at' => now()]);

        return new ManagedUserResource($user->refresh());
    }

    public function enable(User $user): ManagedUserResource
    {
        $this->authorize('enable', $user);

        $user->update(['disabled_at' => null]);

        return new ManagedUserResource($user->refresh());
    }

    /**
     * Whether disabling this user would leave the school with no active
     * director — the lockout guard.
     */
    protected function isLastActiveDirector(User $user): bool
    {
        if (! $user->hasRole(Role::Director->value)) {
            return false;
        }

        $otherActiveDirectors = User::query()
            ->where('school_id', $user->school_id)
            ->whereKeyNot($user->id)
            ->whereNull('disabled_at')
            ->role(Role::Director->value)
            ->count();

        return $otherActiveDirectors === 0;
    }
```

- [ ] **Step 4: Register the routes**

In `api/routes/api.php`, below the users routes:

```php
        Route::post('/users/{user}/disable', [UserController::class, 'disable']);
        Route::post('/users/{user}/enable', [UserController::class, 'enable']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=UserDisableTest`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/app/Http/Controllers/UserController.php api/routes/api.php api/tests/Feature/UserDisableTest.php
git commit -m "feat(api): disable/enable users with self + last-director guardrails

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 8: `POST /v1/users/{user}/resend-invitation`

**Files:**
- Modify: `api/app/Http/Controllers/UserController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/UserResendInvitationTest.php`

**Interfaces:**
- Consumes: `UserPolicy::update`, `UserInvitation::issueFor`, `UserInvitationNotification`.
- Produces: `POST /api/v1/users/{user}/resend-invitation` → 200 `{ data: ManagedUserResource }`; only valid while the user is pending, otherwise 422.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/UserResendInvitationTest.php

use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    $this->director = User::factory()->director()->create();
});

it('resends an invitation to a pending user', function () {
    $pending = User::factory()->teacher()->create([
        'school_id' => $this->director->school_id, 'password' => null,
    ]);

    actingAs($this->director)->postJson("/api/v1/users/{$pending->id}/resend-invitation")
        ->assertOk();

    Notification::assertSentTo($pending, UserInvitationNotification::class);
});

it('refuses to resend to an already-active user', function () {
    $active = User::factory()->teacher()->create(['school_id' => $this->director->school_id]);

    actingAs($this->director)->postJson("/api/v1/users/{$active->id}/resend-invitation")
        ->assertStatus(422);
    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=UserResendInvitationTest`
Expected: FAIL (route missing).

- [ ] **Step 3: Add `resendInvitation` to the controller**

```php
    public function resendInvitation(User $user): ManagedUserResource
    {
        $this->authorize('update', $user);

        if (! $user->isPending()) {
            throw ValidationException::withMessages([
                'user' => 'Solo se puede reenviar la invitación a un usuario pendiente.',
            ]);
        }

        $token = UserInvitation::issueFor($user);
        $user->notify(new UserInvitationNotification($token));

        return new ManagedUserResource($user);
    }
```

- [ ] **Step 4: Register the route**

```php
        Route::post('/users/{user}/resend-invitation', [UserController::class, 'resendInvitation']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=UserResendInvitationTest`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/app/Http/Controllers/UserController.php api/routes/api.php api/tests/Feature/UserResendInvitationTest.php
git commit -m "feat(api): resend invitation to a pending user

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 9: `GET /invitations/{token}` (public validation)

**Files:**
- Create: `api/app/Http/Controllers/InvitationController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/InvitationShowTest.php`

**Interfaces:**
- Consumes: `UserInvitation::findPending`.
- Produces: **public** `GET /api/invitations/{token}` → 200 `{ data: { email, school_name } }` for a valid token; **410 Gone** for an invalid/expired/accepted token. Rate-limited (`throttle:10,1`). No authentication.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/InvitationShowTest.php

use App\Models\User;
use App\Models\UserInvitation;
use function Pest\Laravel\getJson;

it('returns the invitee email and school for a valid token, no auth', function () {
    $user = User::factory()->create(['password' => null, 'email' => 'invitee@example.com']);
    $token = UserInvitation::issueFor($user);

    getJson("/api/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.email', 'invitee@example.com')
        ->assertJsonPath('data.school_name', $user->school->name);
});

it('returns 410 for an unknown token', function () {
    getJson('/api/invitations/does-not-exist')->assertStatus(410);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=InvitationShowTest`
Expected: FAIL (route missing).

- [ ] **Step 3: Create the controller**

```php
<?php
// api/app/Http/Controllers/InvitationController.php

namespace App\Http\Controllers;

use App\Models\UserInvitation;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Public, token-gated endpoints for accepting a staff invitation. The invited
 * user has no session yet, so these routes are deliberately unauthenticated
 * (security rule #4): the single-use, hashed, expiring token IS the gate. They
 * are rate-limited in the route definition.
 */
class InvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = UserInvitation::findPending($token);

        if ($invitation === null) {
            // 410 Gone: the link was never valid, already used, or expired.
            throw new HttpException(410, 'Esta invitación ya no es válida.');
        }

        $user = $invitation->user()->with('school')->first();

        return response()->json(['data' => [
            'email' => $user->email,
            'school_name' => $user->school?->name,
        ]]);
    }
}
```

- [ ] **Step 4: Register the public route**

In `api/routes/api.php`, add `use App\Http\Controllers\InvitationController;` to the imports, then add — **outside** the `auth:sanctum` group, next to the public `/login` route:

```php
// Public, token-gated invitation acceptance (no session yet). The token is the
// authorization gate; both routes are rate-limited.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:10,1');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=InvitationShowTest`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/app/Http/Controllers/InvitationController.php api/routes/api.php api/tests/Feature/InvitationShowTest.php
git commit -m "feat(api): public GET /invitations/{token} validation endpoint

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 10: `POST /invitations/{token}/accept` (set password, activate)

**Files:**
- Create: `api/app/Http/Requests/AcceptInvitationRequest.php`
- Modify: `api/app/Http/Controllers/InvitationController.php`
- Modify: `api/routes/api.php`
- Test: `api/tests/Feature/InvitationAcceptTest.php`

**Interfaces:**
- Consumes: `UserInvitation::findPending`/`markAccepted`.
- Produces: **public** `POST /api/invitations/{token}/accept` body `{ password, password_confirmation }` → 200 `{ message }`; sets the user's password, marks the invitation accepted (single-use), sets `email_verified_at`. 410 for an invalid token; 422 for a weak/unconfirmed password. Rate-limited.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/InvitationAcceptTest.php

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;
use function Pest\Laravel\postJson;

it('sets the password, activates the user, and consumes the token', function () {
    $user = User::factory()->create(['password' => null]);
    $token = UserInvitation::issueFor($user);

    postJson("/api/invitations/{$token}/accept", [
        'password' => 'sup3rsecret',
        'password_confirmation' => 'sup3rsecret',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('sup3rsecret', $user->password))->toBeTrue();
    expect($user->isPending())->toBeFalse();

    // Single-use: the token no longer works.
    postJson("/api/invitations/{$token}/accept", [
        'password' => 'anotherpass1',
        'password_confirmation' => 'anotherpass1',
    ])->assertStatus(410);
});

it('rejects a weak or unconfirmed password', function () {
    $user = User::factory()->create(['password' => null]);
    $token = UserInvitation::issueFor($user);

    postJson("/api/invitations/{$token}/accept", [
        'password' => 'short', 'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrorFor('password');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=InvitationAcceptTest`
Expected: FAIL (route missing).

- [ ] **Step 3: Create the Form Request**

```php
<?php
// api/app/Http/Requests/AcceptInvitationRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Public request: authorization is the token itself (validated in the
 * controller), so authorize() is open here. Password strength mirrors the
 * app:create-user command.
 */
class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }
}
```

- [ ] **Step 4: Add `accept` to the controller**

Add imports to `InvitationController.php`:

```php
use App\Http\Requests\AcceptInvitationRequest;
use Illuminate\Support\Facades\Hash;
```

Add the method:

```php
    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $invitation = UserInvitation::findPending($token);

        if ($invitation === null) {
            throw new HttpException(410, 'Esta invitación ya no es válida.');
        }

        $invitation->user->update([
            'password' => Hash::make($request->validated('password')),
            'email_verified_at' => now(),
        ]);

        $invitation->markAccepted();

        return response()->json(['message' => 'Tu cuenta fue activada. Ya podés iniciar sesión.']);
    }
```

- [ ] **Step 5: Register the public route**

In `api/routes/api.php`, next to the `GET /invitations/{token}` route:

```php
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
    ->middleware('throttle:10,1');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=InvitationAcceptTest`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
./vendor/bin/sail bin pint
git add api/app/Http/Requests/AcceptInvitationRequest.php api/app/Http/Controllers/InvitationController.php api/routes/api.php api/tests/Feature/InvitationAcceptTest.php
git commit -m "feat(api): public POST /invitations/{token}/accept sets password + activates

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 11: Block pending/disabled users at login

**Files:**
- Modify: `api/app/Http/Requests/Auth/LoginRequest.php`
- Test: `api/tests/Feature/LoginLifecycleTest.php`

**Interfaces:**
- Consumes: `User::isPending`/`isDisabled`.
- Produces: login rejects pending users (*"La cuenta aún no fue activada. Revisá tu correo."*) and disabled users (*"Esta cuenta está desactivada."*) with distinct messages under the `email` validation key, before the generic credential check.

> **Trade-off (documented, intentional):** these two messages are keyed on the account's existence, a mild account-enumeration signal. Accepted because this is a director-provisioned internal tool where the invited person already knows they were added, and the guidance materially helps legitimate users. Wrong-password attempts still return the existing generic message.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/LoginLifecycleTest.php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use function Pest\Laravel\postJson;

it('blocks a pending user with a clear message', function () {
    User::factory()->create(['email' => 'pending@example.com', 'password' => null]);

    postJson('/api/login', ['email' => 'pending@example.com', 'password' => 'whatever1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'La cuenta aún no fue activada. Revisá tu correo.');
});

it('blocks a disabled user even with the right password', function () {
    User::factory()->create([
        'email' => 'gone@example.com',
        'password' => Hash::make('correct-horse1'),
        'disabled_at' => now(),
    ]);

    postJson('/api/login', ['email' => 'gone@example.com', 'password' => 'correct-horse1'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Esta cuenta está desactivada.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=LoginLifecycleTest`
Expected: FAIL (pending gets generic message; disabled logs in).

- [ ] **Step 3: Add the lifecycle guard to `authenticate()`**

In `api/app/Http/Requests/Auth/LoginRequest.php`, add `use App\Models\User;` to the imports, then at the **start** of `authenticate()` (before `ensureIsNotRateLimited()`):

```php
        // Give provisioned-but-unusable accounts a precise message before the
        // generic credential check. See design doc for the enumeration
        // trade-off (director-provisioned internal tool).
        $user = User::firstWhere('email', $this->string('email'));

        if ($user?->isDisabled()) {
            throw ValidationException::withMessages([
                'email' => 'Esta cuenta está desactivada.',
            ]);
        }

        if ($user?->isPending()) {
            throw ValidationException::withMessages([
                'email' => 'La cuenta aún no fue activada. Revisá tu correo.',
            ]);
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=LoginLifecycleTest`
Expected: PASS.

- [ ] **Step 5: Run the full backend suite + format, then commit**

Run: `./vendor/bin/sail bin pint && ./vendor/bin/sail test`
Expected: PASS (whole suite green).

```bash
git add api/app/Http/Requests/Auth/LoginRequest.php api/tests/Feature/LoginLifecycleTest.php
git commit -m "feat(api): block pending/disabled users at login with clear messages

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 12: Frontend types, API client, permission + nav

**Files:**
- Modify: `web/src/types.ts`
- Create: `web/src/features/users/usersApi.ts`
- Modify: `web/src/lib/permissions.ts`
- Modify: `web/src/components/nav-items.tsx`
- Test: `web/src/lib/permissions.test.ts` (append) — or create if absent.

**Interfaces:**
- Produces:
  - Types: `UserStatus = "pending" | "active" | "disabled"`; `userStatusLabels`; `ManagedUser { id, name, email, roles: Role[], status: UserStatus }`.
  - `usersApi`: `fetchUsers(search?: string): Promise<ManagedUser[]>`, `createUser(input): Promise<ManagedUser>`, `updateUser(id, input): Promise<ManagedUser>`, `disableUser(id)`, `enableUser(id)`, `resendInvitation(id)`, `fetchInvitation(token): Promise<{ email: string; school_name: string }>`, `acceptInvitation(token, password): Promise<void>`.
  - `canManageUsers(user): boolean` (director).

- [ ] **Step 1: Write the failing permission test**

Append to `web/src/lib/permissions.test.ts` (create the file with this content if it does not exist):

```ts
import { describe, expect, it } from "vitest"
import { canManageUsers } from "@/lib/permissions"
import type { User } from "@/types"

const asRole = (role: User["roles"][number]): User => ({
  id: 1, name: "T", email: "t@e.com", roles: [role],
})

describe("canManageUsers", () => {
  it("is true only for a director", () => {
    expect(canManageUsers(asRole("director"))).toBe(true)
    expect(canManageUsers(asRole("teacher"))).toBe(false)
    expect(canManageUsers(asRole("psychopedagogue"))).toBe(false)
    expect(canManageUsers(null)).toBe(false)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run (from `web/`): `npm run test -- permissions`
Expected: FAIL (`canManageUsers` not exported).

- [ ] **Step 3: Add types**

In `web/src/types.ts`, after the `roleLabels` block:

```ts
export type UserStatus = "pending" | "active" | "disabled"

/** User-facing Spanish labels for the staff lifecycle states. */
export const userStatusLabels: Record<UserStatus, string> = {
  pending: "Pendiente",
  active: "Activo",
  disabled: "Desactivado",
}

/** A staff member as shown on the director-only "Usuarios" screen. */
export interface ManagedUser {
  id: number
  name: string
  email: string
  roles: Role[]
  status: UserStatus
}
```

- [ ] **Step 4: Add the permission helper**

In `web/src/lib/permissions.ts`, after `canManageSubjects`:

```ts
// ── Users / staff management ────────────────────────────────────────────────

/**
 * Manage staff (create, edit, disable, resend invitations). Mirror of
 * `UserPolicy` (role portion): director only. UX-only gate.
 */
export function canManageUsers(user: User | null): boolean {
  return isDirector(user)
}
```

- [ ] **Step 5: Create the API client**

```ts
// web/src/features/users/usersApi.ts
import { api, ensureCsrfCookie } from "@/lib/api"
import type { ManagedUser, Role } from "@/types"

/**
 * Data layer for the "Usuarios" feature. Authenticated endpoints are
 * director-only (mirrored by `canManageUsers`); the two invitation endpoints
 * are public and token-gated (the invited user has no session yet).
 */

export interface CreateUserInput {
  name: string
  email: string
  role: Role
}

export interface UpdateUserInput {
  name?: string
  role?: Role
}

export async function fetchUsers(search?: string): Promise<ManagedUser[]> {
  const { data } = await api.get<{ data: ManagedUser[] }>("/api/v1/users", {
    params: search ? { search } : undefined,
  })
  return data.data
}

export async function createUser(input: CreateUserInput): Promise<ManagedUser> {
  const { data } = await api.post<{ data: ManagedUser }>("/api/v1/users", input)
  return data.data
}

export async function updateUser(id: number, input: UpdateUserInput): Promise<ManagedUser> {
  const { data } = await api.patch<{ data: ManagedUser }>(`/api/v1/users/${id}`, input)
  return data.data
}

export async function disableUser(id: number): Promise<ManagedUser> {
  const { data } = await api.post<{ data: ManagedUser }>(`/api/v1/users/${id}/disable`)
  return data.data
}

export async function enableUser(id: number): Promise<ManagedUser> {
  const { data } = await api.post<{ data: ManagedUser }>(`/api/v1/users/${id}/enable`)
  return data.data
}

export async function resendInvitation(id: number): Promise<void> {
  await api.post(`/api/v1/users/${id}/resend-invitation`)
}

// ── Public invitation acceptance ────────────────────────────────────────────

export async function fetchInvitation(
  token: string,
): Promise<{ email: string; school_name: string }> {
  const { data } = await api.get<{ data: { email: string; school_name: string } }>(
    `/api/invitations/${token}`,
  )
  return data.data
}

export async function acceptInvitation(token: string, password: string): Promise<void> {
  // State-changing POST → ensure the XSRF cookie exists first.
  await ensureCsrfCookie()
  await api.post(`/api/invitations/${token}/accept`, {
    password,
    password_confirmation: password,
  })
}
```

- [ ] **Step 6: Add the nav item**

In `web/src/components/nav-items.tsx`: add `UserCog` to the `lucide-react` import, add `canManageUsers` to the `@/lib/permissions` import, then inside the "Administración" section `items` array (before the Materias entry):

```tsx
        // Staff management is director-only (mirror of UserPolicy).
        ...(canManageUsers(user)
          ? [{ to: "/usuarios", label: "Usuarios", icon: UserCog }]
          : []),
```

- [ ] **Step 7: Run tests + lint + typecheck to verify they pass**

Run (from `web/`): `npm run test -- permissions && npm run typecheck && npm run lint`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add web/src/types.ts web/src/features/users/usersApi.ts web/src/lib/permissions.ts web/src/lib/permissions.test.ts web/src/components/nav-items.tsx
git commit -m "feat(web): users types, API client, canManageUsers + nav item

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 13: `UsersListPage` + route

**Files:**
- Create: `web/src/features/users/UsersListPage.tsx`
- Modify: `web/src/App.tsx`
- Test: `web/src/features/users/UsersListPage.test.tsx`

**Interfaces:**
- Consumes: `usersApi.fetchUsers/disableUser/enableUser/resendInvitation`, `roleLabels`, `userStatusLabels`, `ui/*` components.
- Produces: route `/usuarios` rendering the staff table with search, a status badge per row, and per-row actions (editar link, reenviar invitación for pending, desactivar/reactivar). "Invitar usuario" links to `/usuarios/nueva`.

- [ ] **Step 1: Write the failing test**

```tsx
// web/src/features/users/UsersListPage.test.tsx
import { render, screen, waitFor } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { beforeEach, expect, it, vi } from "vitest"
import { UsersListPage } from "./UsersListPage"
import * as usersApi from "./usersApi"

vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "D", email: "d@e.com", roles: ["director"] } }),
}))

beforeEach(() => {
  vi.spyOn(usersApi, "fetchUsers").mockResolvedValue([
    { id: 2, name: "Ana Docente", email: "ana@e.com", roles: ["teacher"], status: "pending" },
    { id: 3, name: "Beto Director", email: "beto@e.com", roles: ["director"], status: "active" },
  ])
})

it("renders staff with their Spanish status label", async () => {
  render(
    <MemoryRouter>
      <UsersListPage />
    </MemoryRouter>,
  )
  expect(await screen.findByText("Ana Docente")).toBeInTheDocument()
  await waitFor(() => expect(screen.getByText("Pendiente")).toBeInTheDocument())
  expect(screen.getByText("Activo")).toBeInTheDocument()
})
```

- [ ] **Step 2: Run test to verify it fails**

Run (from `web/`): `npm run test -- UsersListPage`
Expected: FAIL (`UsersListPage` not found).

- [ ] **Step 3: Create the page**

```tsx
// web/src/features/users/UsersListPage.tsx
import { useEffect, useState } from "react"
import { Link, Outlet } from "react-router-dom"
import { UserCog } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageUsers } from "@/lib/permissions"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { PageHeader } from "@/components/ui/page-header"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { roleLabels, userStatusLabels, type ManagedUser } from "@/types"
import * as usersApi from "./usersApi"

const statusVariant: Record<ManagedUser["status"], "default" | "secondary" | "outline"> = {
  active: "default",
  pending: "secondary",
  disabled: "outline",
}

/**
 * Director-only "Usuarios" screen (route `/usuarios`). Lists staff with their
 * lifecycle status and per-row actions. Role gating is UX only; the backend
 * enforces access on every request.
 */
export function UsersListPage() {
  const { user } = useAuth()
  const canManage = canManageUsers(user)

  const [users, setUsers] = useState<ManagedUser[] | null>(null)
  const [search, setSearch] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)

  function load(term?: string) {
    return usersApi
      .fetchUsers(term)
      .then(setUsers)
      .catch(() => setError("No pudimos cargar los usuarios."))
  }

  useEffect(() => {
    load()
  }, [])

  async function onToggleDisabled(target: ManagedUser) {
    setBusyId(target.id)
    setError(null)
    try {
      if (target.status === "disabled") {
        await usersApi.enableUser(target.id)
      } else {
        await usersApi.disableUser(target.id)
      }
      await load(search)
    } catch {
      setError("No pudimos actualizar el usuario.")
    } finally {
      setBusyId(null)
    }
  }

  async function onResend(target: ManagedUser) {
    setBusyId(target.id)
    setError(null)
    try {
      await usersApi.resendInvitation(target.id)
    } catch {
      setError("No pudimos reenviar la invitación.")
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Usuarios"
        actions={
          canManage ? (
            <Button asChild>
              <Link to="/usuarios/nueva">Invitar usuario</Link>
            </Button>
          ) : undefined
        }
      />

      <form
        onSubmit={(event) => {
          event.preventDefault()
          load(search)
        }}
        className="flex gap-2"
      >
        <Input
          placeholder="Buscar por nombre o email"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
        <Button type="submit" variant="secondary">
          Buscar
        </Button>
      </form>

      {error && <p className="text-sm text-destructive">{error}</p>}

      {!users ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : users.length === 0 ? (
        <EmptyState icon={UserCog} message="Todavía no hay usuarios." />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nombre</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>Rol</TableHead>
              <TableHead>Estado</TableHead>
              <TableHead className="text-right">Acciones</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {users.map((staff) => (
              <TableRow key={staff.id}>
                <TableCell className="font-medium">{staff.name}</TableCell>
                <TableCell>{staff.email}</TableCell>
                <TableCell>{staff.roles.map((role) => roleLabels[role]).join(", ")}</TableCell>
                <TableCell>
                  <Badge variant={statusVariant[staff.status]}>
                    {userStatusLabels[staff.status]}
                  </Badge>
                </TableCell>
                <TableCell className="flex justify-end gap-2">
                  {canManage && (
                    <>
                      <Button asChild variant="ghost" size="sm">
                        <Link to={`/usuarios/${staff.id}`}>Editar</Link>
                      </Button>
                      {staff.status === "pending" && (
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={busyId === staff.id}
                          onClick={() => onResend(staff)}
                        >
                          Reenviar invitación
                        </Button>
                      )}
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={busyId === staff.id}
                        onClick={() => onToggleDisabled(staff)}
                      >
                        {staff.status === "disabled" ? "Reactivar" : "Desactivar"}
                      </Button>
                    </>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      {/* Nested create/edit form route renders here. */}
      <Outlet context={{ reload: () => load(search) }} />
    </div>
  )
}
```

> Note: if `PageHeader` does not accept an `actions` prop, render the "Invitar usuario" button directly below `<PageHeader title="Usuarios" />` instead. Verify the prop against `web/src/components/ui/page-header.tsx` before implementing.

- [ ] **Step 4: Add the route**

In `web/src/App.tsx`, add the import `import { UsersListPage } from "@/features/users/UsersListPage"` and `import { UserFormPage } from "@/features/users/UserFormPage"` (created in Task 14), then add the route near `/materias`:

```tsx
          <Route
            path="/usuarios"
            element={
              <ProtectedLayout>
                <UsersListPage />
              </ProtectedLayout>
            }
          >
            <Route path="nueva" element={<UserFormPage />} />
            <Route path=":id" element={<UserFormPage />} />
          </Route>
```

> If Task 14 is not yet done, temporarily omit the two nested `<Route>` children and the `UserFormPage` import so the app compiles; re-add them in Task 14.

- [ ] **Step 5: Run test to verify it passes**

Run (from `web/`): `npm run test -- UsersListPage`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add web/src/features/users/UsersListPage.tsx web/src/features/users/UsersListPage.test.tsx web/src/App.tsx
git commit -m "feat(web): UsersListPage with status badges + row actions

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 14: `UserFormPage` (invite / edit)

**Files:**
- Create: `web/src/features/users/UserFormPage.tsx`
- Test: `web/src/features/users/UserFormPage.test.tsx`

**Interfaces:**
- Consumes: `usersApi.createUser/updateUser/fetchUsers`, `roleLabels`, `useOutletContext` `{ reload }` from `UsersListPage`.
- Produces: a dialog form. Create mode (`/usuarios/nueva`): name + email + role; on success shows *"Invitación enviada a {email}"* and reloads the list. Edit mode (`/usuarios/:id`): name + role (email shown read-only).

- [ ] **Step 1: Write the failing test**

```tsx
// web/src/features/users/UserFormPage.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { beforeEach, expect, it, vi } from "vitest"
import { UserFormPage } from "./UserFormPage"
import * as usersApi from "./usersApi"

beforeEach(() => {
  vi.spyOn(usersApi, "createUser").mockResolvedValue({
    id: 9, name: "Ana", email: "ana@e.com", roles: ["teacher"], status: "pending",
  })
})

function renderCreate() {
  return render(
    <MemoryRouter initialEntries={["/usuarios/nueva"]}>
      <Routes>
        <Route path="/usuarios" element={<div />}>
          <Route path="nueva" element={<UserFormPage />} />
        </Route>
      </Routes>
    </MemoryRouter>,
  )
}

it("creates a user and confirms the invitation was sent", async () => {
  renderCreate()
  await userEvent.type(screen.getByLabelText("Nombre"), "Ana Docente")
  await userEvent.type(screen.getByLabelText("Email"), "ana@e.com")
  await userEvent.click(screen.getByRole("button", { name: /invitar/i }))

  expect(usersApi.createUser).toHaveBeenCalledWith({
    name: "Ana Docente",
    email: "ana@e.com",
    role: "teacher",
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run (from `web/`): `npm run test -- UserFormPage`
Expected: FAIL (`UserFormPage` not found).

- [ ] **Step 3: Create the form page**

```tsx
// web/src/features/users/UserFormPage.tsx
import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useOutletContext, useParams } from "react-router-dom"
import { isAxiosError } from "axios"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SingleSelect } from "@/components/ui/single-select"
import { roleLabels, type Role } from "@/types"
import * as usersApi from "./usersApi"

const ROLE_OPTIONS: { value: Role; label: string }[] = (
  Object.keys(roleLabels) as Role[]
).map((role) => ({ value: role, label: roleLabels[role] }))

const schema = z.object({
  name: z.string().min(1, "Ingresá el nombre"),
  email: z.string().min(1, "Ingresá el email").email("Email inválido"),
  role: z.enum(["teacher", "director", "psychopedagogue"]),
})

type FormValues = z.infer<typeof schema>

interface ListContext {
  reload: () => void
}

/**
 * Create ("Invitar usuario") / edit form, shown as a modal over the list. In
 * create mode all three fields are editable and success sends an invitation
 * email; in edit mode the email is read-only (immutable server-side).
 */
export function UserFormPage() {
  const navigate = useNavigate()
  const { id } = useParams()
  const isEdit = id !== undefined
  const { reload } = useOutletContext<ListContext>()

  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", email: "", role: "teacher" },
  })

  // In edit mode, hydrate from the list (single source; avoids a second fetch).
  useEffect(() => {
    if (!isEdit) return
    usersApi.fetchUsers().then((users) => {
      const found = users.find((u) => u.id === Number(id))
      if (found) {
        reset({ name: found.name, email: found.email, role: found.roles[0] ?? "teacher" })
      }
    })
  }, [id, isEdit, reset])

  function close() {
    navigate("/usuarios")
  }

  async function onSubmit(values: FormValues) {
    setFormError(null)
    try {
      if (isEdit) {
        await usersApi.updateUser(Number(id), { name: values.name, role: values.role })
      } else {
        await usersApi.createUser(values)
      }
      reload()
      close()
    } catch (error) {
      if (isAxiosError(error) && error.response?.status === 422) {
        setFormError("Revisá los datos. ¿Ya existe un usuario con ese email?")
      } else {
        setFormError("No pudimos guardar el usuario.")
      }
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && close()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{isEdit ? "Editar usuario" : "Invitar usuario"}</DialogTitle>
        </DialogHeader>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="name">Nombre</Label>
            <Input id="name" {...register("name")} />
            {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" type="email" readOnly={isEdit} {...register("email")} />
            {isEdit && (
              <p className="text-xs text-muted-foreground">
                El email no se puede modificar.
              </p>
            )}
            {errors.email && <p className="text-sm text-destructive">{errors.email.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="role">Rol</Label>
            <SingleSelect
              options={ROLE_OPTIONS}
              value={watch("role")}
              onChange={(value) => setValue("role", value as Role)}
            />
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={close}>
              Cancelar
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting
                ? "Guardando…"
                : isEdit
                  ? "Guardar cambios"
                  : "Invitar"}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
```

> Before implementing, verify the props of `SingleSelect` (`web/src/components/ui/single-select.tsx`) and `Dialog` (`web/src/components/ui/dialog.tsx`) and adjust the `options`/`value`/`onChange` wiring to match the actual component API.

- [ ] **Step 4: Run test to verify it passes**

Run (from `web/`): `npm run test -- UserFormPage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add web/src/features/users/UserFormPage.tsx web/src/features/users/UserFormPage.test.tsx
git commit -m "feat(web): UserFormPage invite/edit dialog

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Task 15: `AcceptInvitationPage` (public) + route

**Files:**
- Create: `web/src/features/auth/AcceptInvitationPage.tsx`
- Modify: `web/src/App.tsx`
- Test: `web/src/features/auth/AcceptInvitationPage.test.tsx`

**Interfaces:**
- Consumes: `usersApi.fetchInvitation/acceptInvitation`.
- Produces: public route `/aceptar-invitacion` that reads `?token=`, shows the school + email, takes a password + confirmation, calls accept, then redirects to `/login`. Shows a clear invalid/expired state on a 410.

- [ ] **Step 1: Write the failing test**

```tsx
// web/src/features/auth/AcceptInvitationPage.test.tsx
import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { beforeEach, expect, it, vi } from "vitest"
import { AcceptInvitationPage } from "./AcceptInvitationPage"
import * as usersApi from "@/features/users/usersApi"

beforeEach(() => {
  vi.spyOn(usersApi, "fetchInvitation").mockResolvedValue({
    email: "ana@e.com",
    school_name: "Colegio Uno",
  })
})

it("shows the invitee email and school for a valid token", async () => {
  render(
    <MemoryRouter initialEntries={["/aceptar-invitacion?token=abc"]}>
      <AcceptInvitationPage />
    </MemoryRouter>,
  )
  expect(await screen.findByText("ana@e.com")).toBeInTheDocument()
  expect(screen.getByText(/Colegio Uno/)).toBeInTheDocument()
})
```

- [ ] **Step 2: Run test to verify it fails**

Run (from `web/`): `npm run test -- AcceptInvitationPage`
Expected: FAIL (`AcceptInvitationPage` not found).

- [ ] **Step 3: Create the page**

```tsx
// web/src/features/auth/AcceptInvitationPage.tsx
import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useSearchParams } from "react-router-dom"
import { isAxiosError } from "axios"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import * as usersApi from "@/features/users/usersApi"

// Mirror of the backend rule (Password::min(8)->letters()->numbers()).
const schema = z
  .object({
    password: z
      .string()
      .min(8, "Mínimo 8 caracteres")
      .regex(/[a-zA-Z]/, "Debe incluir letras")
      .regex(/[0-9]/, "Debe incluir números"),
    confirm: z.string(),
  })
  .refine((data) => data.password === data.confirm, {
    path: ["confirm"],
    message: "Las contraseñas no coinciden",
  })

type FormValues = z.infer<typeof schema>

/**
 * Public invitation-acceptance screen (route `/aceptar-invitacion`, outside the
 * protected layout). Validates the `?token=` param, then lets the invitee set
 * their password to activate the account.
 */
export function AcceptInvitationPage() {
  const [params] = useSearchParams()
  const token = params.get("token") ?? ""
  const navigate = useNavigate()

  const [invitation, setInvitation] = useState<{ email: string; school_name: string } | null>(null)
  const [invalid, setInvalid] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { password: "", confirm: "" },
  })

  useEffect(() => {
    if (!token) {
      setInvalid(true)
      return
    }
    usersApi
      .fetchInvitation(token)
      .then(setInvitation)
      .catch(() => setInvalid(true))
  }, [token])

  async function onSubmit(values: FormValues) {
    setFormError(null)
    try {
      await usersApi.acceptInvitation(token, values.password)
      navigate("/login", { replace: true })
    } catch (error) {
      if (isAxiosError(error) && error.response?.status === 410) {
        setInvalid(true)
      } else {
        setFormError("No pudimos activar tu cuenta. Intentá de nuevo.")
      }
    }
  }

  return (
    <div className="flex min-h-svh items-center justify-center bg-muted/40 p-4">
      <Card className="w-full max-w-sm">
        {invalid ? (
          <>
            <CardHeader>
              <CardTitle className="text-2xl">Invitación no válida</CardTitle>
              <CardDescription>
                Este enlace ya fue usado o venció. Pedile al director que te reenvíe la invitación.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button className="w-full" onClick={() => navigate("/login")}>
                Ir a iniciar sesión
              </Button>
            </CardContent>
          </>
        ) : !invitation ? (
          <CardHeader>
            <CardTitle className="text-2xl">Aula+</CardTitle>
            <CardDescription>Cargando invitación…</CardDescription>
          </CardHeader>
        ) : (
          <>
            <CardHeader>
              <CardTitle className="text-2xl">Activá tu cuenta</CardTitle>
              <CardDescription>
                {invitation.school_name} · <span>{invitation.email}</span>
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
                <div className="grid gap-2">
                  <Label htmlFor="password">Contraseña</Label>
                  <Input
                    id="password"
                    type="password"
                    autoComplete="new-password"
                    {...register("password")}
                  />
                  {errors.password && (
                    <p className="text-sm text-destructive">{errors.password.message}</p>
                  )}
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="confirm">Repetir contraseña</Label>
                  <Input
                    id="confirm"
                    type="password"
                    autoComplete="new-password"
                    {...register("confirm")}
                  />
                  {errors.confirm && (
                    <p className="text-sm text-destructive">{errors.confirm.message}</p>
                  )}
                </div>
                {formError && (
                  <p role="alert" className="text-sm text-destructive">
                    {formError}
                  </p>
                )}
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Activando…" : "Activar cuenta"}
                </Button>
              </form>
            </CardContent>
          </>
        )}
      </Card>
    </div>
  )
}
```

- [ ] **Step 4: Add the public route**

In `web/src/App.tsx`, add `import { AcceptInvitationPage } from "@/features/auth/AcceptInvitationPage"`, then next to the `/login` route (outside `ProtectedLayout`):

```tsx
          <Route path="/aceptar-invitacion" element={<AcceptInvitationPage />} />
```

- [ ] **Step 5: Run test to verify it passes**

Run (from `web/`): `npm run test -- AcceptInvitationPage`
Expected: PASS.

- [ ] **Step 6: Full frontend gate + commit**

Run (from `web/`): `npm run lint && npm run typecheck && npm run test && npm run build`
Expected: all PASS.

```bash
git add web/src/features/auth/AcceptInvitationPage.tsx web/src/features/auth/AcceptInvitationPage.test.tsx web/src/App.tsx
git commit -m "feat(web): public AcceptInvitationPage + route

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01EcRi8GxNdoa9FYpxeKyATU"
```

---

## Self-Review Notes

- **Spec coverage:** schema (T1), model/helpers (T2), policy (T3), list (T4), create+email (T5), show/edit + email-immutable (T6), disable/enable + guardrails (T7), resend (T8), public validate (T9), public accept + password rules (T10), login blocking (T11), FE types/api/perms/nav (T12), list screen (T13), invite/edit form (T14), public accept screen (T15). All spec sections mapped.
- **Status derivation** is consistent everywhere: `disabled → pending(password null) → active`, matching the spec table (T2/T4 resource).
- **Type/signature consistency:** `ManagedUser`, `UserStatus`, and every `usersApi` function name are declared in T12 and used unchanged in T13–T15; backend `ManagedUserResource` shape (T4) matches the FE `ManagedUser` type (T12).
- **Component-API caveats** (`PageHeader.actions`, `SingleSelect`, `Dialog`) are flagged inline to verify against the real `ui/` components before wiring — the repo's shadcn variants may differ.
