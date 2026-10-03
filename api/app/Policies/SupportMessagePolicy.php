<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Any staff member (all three roles) may send a message to the Aula+ team.
 * Nobody reads them back through the app, so there is no view policy.
 */
class SupportMessagePolicy
{
    public function create(User $user): bool
    {
        return $user->hasAnyRole(Role::values());
    }
}
