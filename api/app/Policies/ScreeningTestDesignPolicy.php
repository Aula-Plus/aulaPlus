<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use App\Models\User;

/**
 * Screen 08: Psicopedagogía and Dirección both author designs, and a saved
 * design rules right away (no approval step — decided 15/10). Dirección gets
 * notified of each change and can revert it. No path grants a teacher.
 */
class ScreeningTestDesignPolicy
{
    public function view(User $user, ScreeningTestDesign $design): bool
    {
        return $user->school_id === $design->school_id
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    /**
     * Called with the parent {@see ScreeningTestType} so the tenant is checked
     * here too, on top of the route-model-binding scope (defence in depth).
     */
    public function create(User $user, ScreeningTestType $type): bool
    {
        return $user->school_id === $type->school_id
            && $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    /**
     * "Volver atrás" is Dirección's (screen 08). Role/tenant only — whether
     * the design is the one in force is a business-state precondition the
     * controller checks separately (422, not 403).
     */
    public function revert(User $user, ScreeningTestDesign $design): bool
    {
        return $user->school_id === $design->school_id
            && $user->hasRole(Role::Director->value);
    }
}
