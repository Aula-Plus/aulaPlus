<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\SchoolYear;
use App\Models\User;

/**
 * Screen 26 «Año y calendario» (ClickUp 1dfd3mc4bh7) is direction's only:
 * reading the year, setting the day closing is enabled from, its periods,
 * closing it, undoing the closing, opening the next one and enabling loading in a group of a
 * closed year. Nobody else enters (P26-ANO-04); always tied to the user's own
 * school (defence in depth over the tenant scope).
 */
class SchoolYearPolicy
{
    /**
     * Entering the screen at all: checked before the school's year is looked
     * up (or created on a first visit), so nobody else ever creates one.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function view(User $user, SchoolYear $schoolYear): bool
    {
        return $this->isDirectorOf($user, $schoolYear);
    }

    /** Closing date and periods. */
    public function update(User $user, SchoolYear $schoolYear): bool
    {
        return $this->isDirectorOf($user, $schoolYear);
    }

    public function close(User $user, SchoolYear $schoolYear): bool
    {
        return $this->isDirectorOf($user, $schoolYear);
    }

    /** «Deshacer el cierre». */
    public function reopen(User $user, SchoolYear $schoolYear): bool
    {
        return $this->isDirectorOf($user, $schoolYear);
    }

    public function openNext(User $user, SchoolYear $schoolYear): bool
    {
        return $this->isDirectorOf($user, $schoolYear);
    }

    /** Enabling (or ending) loading in a group of a closed year. */
    public function manageWriteWindows(User $user, SchoolYear $schoolYear): bool
    {
        return $this->isDirectorOf($user, $schoolYear);
    }

    private function isDirectorOf(User $user, SchoolYear $schoolYear): bool
    {
        return $user->school_id === $schoolYear->school_id
            && $user->hasRole(Role::Director->value);
    }
}
