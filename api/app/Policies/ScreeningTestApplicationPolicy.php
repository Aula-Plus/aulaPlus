<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Group;
use App\Models\ScreeningTestApplication;
use App\Models\User;

/**
 * Applying a screening test to a group (docs/prompts/11-pruebas-de-sondeo.md
 * §§2, 4). School-wide module, never a teacher. Applying and the two
 * de-anonymising views (roster, results-by-code) are psychopedagogy-only; a
 * director may list/see applications but NOT the roster or the results, since
 * those cross code <-> student (spec §2, "solo visible para psicopedagogía").
 */
class ScreeningTestApplicationPolicy
{
    /**
     * List a group's applications. Called with the route {@see Group} so the
     * tenant is verified and a teacher is excluded regardless of whether they
     * lead the group (unlike GroupPolicy::view).
     */
    public function viewAny(User $user, Group $group): bool
    {
        return $user->school_id === $group->school_id
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    public function view(User $user, ScreeningTestApplication $application): bool
    {
        return $user->school_id === $application->school_id
            && $user->hasAnyRole(Role::schoolWideValues());
    }

    /**
     * Only psychopedagogy applies a screening test to a group (spec §2).
     */
    public function create(User $user, Group $group): bool
    {
        return $user->school_id === $group->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }

    /**
     * «Recalcular con el nivel actual» (ClickUp 86e3t4qjh): psychopedagogy and
     * direction, the two roles that look after the impact view.
     */
    public function recalculate(User $user, ScreeningTestApplication $application): bool
    {
        return $user->school_id === $application->school_id
            && $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }

    /**
     * The code -> student roster: psychopedagogy only (spec §2). This is the
     * single endpoint that de-anonymises results.
     */
    public function viewRoster(User $user, ScreeningTestApplication $application): bool
    {
        return $user->school_id === $application->school_id
            && $user->hasRole(Role::Psychopedagogue->value);
    }

    /**
     * Read the by-code results (no names, no roster): Psicopedagogía and
     * Dirección (ClickUp 86e3dt70g, decision of 24/10; screen 07 «Resultados
     * por grupo»). Reading is separate from loading/correcting scores
     * ({@see ScreeningTestResultPolicy::update}, psychopedagogy only) and from
     * the code → student roster ({@see self::viewRoster()}).
     */
    public function viewResults(User $user, ScreeningTestApplication $application): bool
    {
        return $user->school_id === $application->school_id
            && $user->hasAnyRole([Role::Psychopedagogue->value, Role::Director->value]);
    }
}
