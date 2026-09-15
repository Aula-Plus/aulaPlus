<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    public function store(StoreUserRequest $request): JsonResponse
    {
        // User does not use BelongsToSchool, so school_id is set explicitly
        // from the authenticated director. Password stays null until the
        // invitee accepts. create + role assignment + invitation issuance
        // run in a transaction so a mid-sequence failure can't leave a
        // half-created user; the notification is queued and only fires
        // after the transaction commits.
        [$user, $token] = DB::transaction(function () use ($request) {
            $user = User::create([
                'school_id' => $request->user()->school_id,
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => null,
            ]);

            $user->assignRole($request->validated('role'));

            $token = UserInvitation::issueFor($user);

            return [$user, $token];
        });

        $user->notify(new UserInvitationNotification($token));

        return (new ManagedUserResource($user))->response()->setStatusCode(201);
    }

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
}
