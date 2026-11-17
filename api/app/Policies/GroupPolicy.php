<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Group;
use App\Models\User;
use App\Services\SchoolYears\SchoolYearCalendar;
use Illuminate\Auth\Access\Response;

/**
 * Reference policy showing the expected authorization pattern for this codebase.
 *
 * Two layers of protection, both enforced server-side (never trusting the client):
 *   1. Tenant isolation — every check first asserts the user and the group share
 *      a school. This is defence-in-depth on top of the SchoolScope global scope.
 *   2. Role rules — director/psychopedagogue have school-wide visibility; a
 *      teacher is limited to the groups they lead (group_teacher pivot).
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

    /**
     * Screen 05 «Comparativa por año de cursada»: Psicopedagogía and Dirección
     * only (P05-DEC-04 — comparing two whole groups is an institutional
     * reading, never a comparison between colleagues). Each compared group is
     * still checked with `view` (tenant isolation).
     */
    public function compare(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }

    public function view(User $user, Group $group): bool
    {
        if (! $this->sharesSchool($user, $group)) {
            return false;
        }

        return $user->hasAnyRole(Role::schoolWideValues())
            || $user->teachesGroup($group);
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

    /**
     * Direction only. A group of a closed year is history (screen 26): it is
     * never deleted, not even while direction enabled loading in it — an
     * enablement lets its structure be corrected, not removed.
     */
    public function delete(User $user, Group $group): Response
    {
        if (! $this->sharesSchool($user, $group) || ! $user->hasRole(Role::Director->value)) {
            return Response::deny();
        }

        if (app(SchoolYearCalendar::class)->isInClosedYear($group)) {
            return Response::deny("School year {$group->school_year} is closed: its groups cannot be deleted.", 'school_year_closed');
        }

        return Response::allow();
    }

    /**
     * Choose or change a group's level (ClickUp 86e3t4qjh): direction, and
     * psychopedagogy only when direction has handed it that power for the
     * school (`schools.psychopedagogue_can_set_group_level`).
     */
    public function setLevel(User $user, Group $group): bool
    {
        if (! $this->sharesSchool($user, $group)) {
            return false;
        }

        if ($user->hasRole(Role::Director->value)) {
            return true;
        }

        return $user->hasRole(Role::Psychopedagogue->value)
            && (bool) $user->school?->psychopedagogue_can_set_group_level;
    }

    protected function sharesSchool(User $user, Group $group): bool
    {
        return $user->school_id === $group->school_id;
    }

    /**
     * Screen 26 «Año y calendario» (ClickUp 1dfd3mc4bh7): once its year is
     * closed, a group's data (grades, observations, assessments, plans,
     * classes, screenings) and its structure (the group, its teachers, its
     * curricular offer and its level — Eitán 17/11) are history and
     * read-only for everybody — unless direction enabled loading in that
     * group until a date, which includes correcting its structure. Checked by the
     * `group.writable` middleware on every group-data write route, on top of
     * the endpoint's own role rules (it never grants anything by itself).
     */
    public function writeInYear(User $user, Group $group): Response
    {
        if ($user->school_id !== $group->school_id) {
            return Response::denyAsNotFound();
        }

        if (app(SchoolYearCalendar::class)->isReadOnly($group)) {
            return Response::deny("School year {$group->school_year} is closed: this group is read-only.", 'school_year_closed');
        }

        return Response::allow();
    }
}
