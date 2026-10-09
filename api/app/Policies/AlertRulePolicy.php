<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\AlertRule;
use App\Models\User;

/**
 * The alert settings screen (ClickUp 86e3jpzcv: "dirección y psicopedagogía,
 * en la puesta en marcha") — the sustained-low-performance conditions and who
 * each alert type reaches first. Teachers neither read nor change it.
 * Tenant isolation is asserted first (defence in depth over SchoolScope).
 */
class AlertRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }

    public function update(User $user, AlertRule $rule): bool
    {
        return $user->school_id === $rule->school_id
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    public function delete(User $user, AlertRule $rule): bool
    {
        return $this->update($user, $rule);
    }

    /**
     * Change who an alert type reaches first.
     */
    public function configureRouting(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }
}
