<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use App\Models\User;

/**
 * Design authoring vs. approval split (docs/prompts/11-pruebas-de-sondeo.md
 * §§1, 4): psychopedagogy authors a design (it lands `approved = null`), and
 * `approve`/`reject` are director-only — mirroring the
 * AccommodationApprovalController workflow. No path grants a teacher.
 */
class ScreeningTestDesignPolicy
{
    public function view(User $user, ScreeningTestDesign $design): bool
    {
        return $user->school_id === $design->school_id
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    /**
     * Only psychopedagogy creates/edits a design. Called with the parent
     * {@see ScreeningTestType} so the tenant is checked here too, on top of the
     * route-model-binding scope (defence in depth).
     */
    public function create(User $user, ScreeningTestType $type): bool
    {
        return $user->school_id === $type->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }

    /**
     * Approving a design is director-only (spec §§1, 4). Role/tenant only —
     * whether the design is actually pending is a business-state precondition
     * the controller checks separately (422, not 403), exactly as
     * AccommodationPolicy::approve documents.
     */
    public function approve(User $user, ScreeningTestDesign $design): bool
    {
        return $user->school_id === $design->school_id
            && $user->hasRole(Role::Director->value);
    }

    /**
     * Same authorization as {@see self::approve()} — the other outcome of the
     * same director-only workflow.
     */
    public function reject(User $user, ScreeningTestDesign $design): bool
    {
        return $this->approve($user, $design);
    }
}
