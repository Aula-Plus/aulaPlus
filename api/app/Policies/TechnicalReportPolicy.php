<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\TechnicalReport;
use App\Models\User;

/**
 * Uploaded technical/psychopedagogical reports — sensitive data about a minor
 * (CLAUDE.md security rule 11). Creation is deliberately narrower than
 * Accommodation/Barrier: only `psychopedagogue` may upload one, not even
 * `director` (doc02 §2 explicitly calls this out).
 *
 * Update/delete are intentionally not implemented — doc02 §2 only specifies
 * create and view for this entity in this session; a Policy method that
 * doesn't exist denies by default (Laravel Gate), so this is a safe default,
 * not an oversight.
 */
class TechnicalReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }

    public function view(User $user, TechnicalReport $technicalReport): bool
    {
        return $this->sharesSchool($user, $technicalReport)
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Psychopedagogue->value);
    }

    protected function sharesSchool(User $user, TechnicalReport $technicalReport): bool
    {
        return $user->school_id === $technicalReport->school_id;
    }
}
