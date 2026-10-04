<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\Group;
use App\Models\User;

/**
 * Alerts surface behavior/performance concerns about a student — sensitive
 * data about a minor (CLAUDE.md security rule 11).
 *
 * Who sees an alert is decided by {@see Alert::scopeVisibleTo()} (the single
 * source of truth, also used by the list endpoints): legacy alerts keep the
 * original rule (the same director/psychopedagogue rule as
 * StudentPolicy::viewClinicalProfile), and sustained-low-performance alerts
 * (ClickUp 86e3jpzcv) reach only the recipients snapshotted on them — by
 * default, only the teacher of that subject in the student's group.
 */
class AlertPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Alert $alert): bool
    {
        return $alert->isVisibleTo($user);
    }

    /**
     * Choose one of the three ways out (ClickUp 86e3jpzdp) — see
     * {@see Alert::canBeActedOnBy()}.
     */
    public function act(User $user, Alert $alert): bool
    {
        return $alert->canBeActedOnBy($user);
    }

    /**
     * See {@see Alert::canBeResolvedBy()}: a new alert closes only after a way
     * out was chosen, and only by the person responsible.
     */
    public function resolve(User $user, Alert $alert): bool
    {
        return $alert->canBeResolvedBy($user);
    }

    /**
     * Authorization for GET /api/v1/groups/{group}/alerts. Anyone who can see
     * the group may call it; the endpoint then returns only the alerts that
     * pass {@see Alert::scopeVisibleTo()} for that user.
     */
    public function viewForGroup(User $user, Group $group): bool
    {
        return $user->school_id === $group->school_id
            && ($user->hasAnyRole(Role::schoolWideValues()) || $user->teachesGroup($group));
    }
}
