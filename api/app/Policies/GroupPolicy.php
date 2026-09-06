<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Group;
use App\Models\User;

/**
 * Reference policy showing the expected authorization pattern for this codebase.
 *
 * Two layers of protection, both enforced server-side (never trusting the client):
 *   1. Tenant isolation — every check first asserts the user and the group share
 *      a school. This is defence-in-depth on top of the SchoolScope global scope.
 *   2. Role rules — director/psychopedagogue have school-wide visibility; a
 *      teacher is limited to the groups they lead (group.teacher_id === user.id).
 *
 * Laravel auto-discovers this policy for App\Models\Group by naming convention.
 */
class GroupPolicy
{
    /**
     * Listing is allowed for any authenticated staff member; the SchoolScope and
     * per-record `view` checks constrain what they actually receive.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Group $group): bool
    {
        if (! $this->sharesSchool($user, $group)) {
            return false;
        }

        return $user->hasAnyRole(Role::schoolWideValues())
            || $this->leadsGroup($user, $group);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, Group $group): bool
    {
        return $this->sharesSchool($user, $group)
            && $user->hasRole(Role::Director->value);
    }

    public function delete(User $user, Group $group): bool
    {
        return $this->sharesSchool($user, $group)
            && $user->hasRole(Role::Director->value);
    }

    protected function sharesSchool(User $user, Group $group): bool
    {
        return $user->school_id === $group->school_id;
    }

    protected function leadsGroup(User $user, Group $group): bool
    {
        return $group->teacher_id !== null && $group->teacher_id === $user->id;
    }
}
