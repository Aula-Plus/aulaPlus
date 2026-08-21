<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\User;

/**
 * Holds sensitive data about a minor's learning profile — see CLAUDE.md
 * security rule 11.
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
     * Role/tenant authorization only — deliberately does NOT check
     * `requires_external_approval`/`approved` here. Whether approving makes
     * sense *right now* is a business-state precondition, not an
     * authorization decision: the controller checks it separately and
     * responds 422 (not 403) when it fails, per
     * docs/prompts/03-flujos-aprobacion-trazabilidad.md §3.
     */
    public function approve(User $user, Accommodation $accommodation): bool
    {
        return $this->sharesSchool($user, $accommodation)
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    /**
     * Same authorization as {@see self::approve()} — rejecting is the other
     * outcome of the same director/psychopedagogue-only workflow.
     */
    public function reject(User $user, Accommodation $accommodation): bool
    {
        return $this->approve($user, $accommodation);
    }

    protected function sharesSchool(User $user, Accommodation $accommodation): bool
    {
        return $user->school_id === $accommodation->school_id;
    }
}
