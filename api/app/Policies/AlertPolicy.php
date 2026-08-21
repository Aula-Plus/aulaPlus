<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\Group;
use App\Models\User;

/**
 * Alerts surface behavior/performance concerns about a student — sensitive
 * data about a minor (CLAUDE.md security rule 11). Per docs/prompts/
 * 04-seguimiento-institucional.md §4 ("cualquier rol que pueda ver el perfil
 * clínico del alumno puede resolver"), authorization here deliberately
 * delegates to {@see StudentPolicy::viewClinicalProfile()} instead of
 * re-deriving the director/psychopedagogue rule — the *same* Sesión 2 rule
 * this session must reuse rather than duplicate.
 */
class AlertPolicy
{
    public function __construct(protected StudentPolicy $studentPolicy) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Alert $alert): bool
    {
        return $this->studentPolicy->viewClinicalProfile($user, $alert->student);
    }

    public function resolve(User $user, Alert $alert): bool
    {
        return $this->view($user, $alert);
    }

    /**
     * Authorization for GET /api/v1/groups/{group}/alerts: there is no single
     * student to check viewClinicalProfile against, so this applies the exact
     * same substantive rule (school-wide clinical role, same school) at the
     * group level instead.
     */
    public function viewForGroup(User $user, Group $group): bool
    {
        return $user->school_id === $group->school_id
            && $user->hasAnyRole(Role::schoolWideValues());
    }
}
