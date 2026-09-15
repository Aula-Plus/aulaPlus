<?php

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
