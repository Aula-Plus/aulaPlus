<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Http\JsonResponse;
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
}
