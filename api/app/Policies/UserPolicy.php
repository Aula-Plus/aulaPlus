<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Staff account management. director-only, same two-layer pattern as the
 * other Policies: tenant isolation first, then the role rule.
 *
 * "Desactivar" (deactivate) in the permission matrix has no separate model
 * action yet — it's covered by `update` (a director toggling an `active`-like
 * state is still updating the user record). Delete is intentionally not
 * granted to anyone yet: no hard-delete endpoint for staff accounts exists in
 * this session's scope.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function view(User $user, User $target): bool
    {
        return $this->sharesSchool($user, $target)
            && $user->hasRole(Role::Director->value);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, User $target): bool
    {
        return $this->sharesSchool($user, $target)
            && $user->hasRole(Role::Director->value);
    }

    protected function sharesSchool(User $user, User $target): bool
    {
        return $user->school_id === $target->school_id;
    }
}
