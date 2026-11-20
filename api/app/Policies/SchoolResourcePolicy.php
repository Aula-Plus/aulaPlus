<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\SchoolResource;
use App\Models\User;

/** Screen 23: only Dirección loads the school's resources (P23-REC-02). */
class SchoolResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function delete(User $user, SchoolResource $resource): bool
    {
        return $user->school_id === $resource->school_id && $user->hasRole(Role::Director->value);
    }
}
