<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\School;
use App\Models\User;

/**
 * Only the adoption dashboard needs a School-level authorization decision
 * today (docs/prompts/04-seguimiento-institucional.md §5) — director of that
 * specific school, not any director.
 */
class SchoolPolicy
{
    public function viewAdoptionDashboard(User $user, School $school): bool
    {
        return $user->school_id === $school->id
            && $user->hasRole(Role::Director->value);
    }
}
