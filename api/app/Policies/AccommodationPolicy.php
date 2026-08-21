<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\User;

/**
 * Holds sensitive data about a minor's learning profile — see CLAUDE.md
 * security rule 11. Authorship/approval workflow fields exist on the model
 * but the workflow itself (who proposes, who validates) is wired up in
 * Session 3; this Policy only covers the access rules already specified for
 * this session.
 */
class AccommodationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(Role::values());
    }

    public function update(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation);
    }

    public function delete(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation);
    }

    /**
     * Approving an accommodation only makes sense when it actually requires
     * external approval (`requires_external_approval`); a director or
     * psychopedagogue can never "approve" one that doesn't need it.
     */
    public function approve(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation)
            && $accommodation->requires_external_approval
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    protected function sharesSchool(User $user, Accommodation $accommodation): bool
    {
        return $user->school_id === $accommodation->school_id;
    }
}
