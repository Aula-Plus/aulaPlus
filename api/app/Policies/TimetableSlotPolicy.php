<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\TimetableSlot;
use App\Models\User;

/**
 * «Mis horarios» is private to its teacher (ClickUp 1dfd3mc4bgh): nobody else
 * reads or changes a slot — not Psychopedagogy, not Direction. There is no
 * school-wide branch on purpose. Tenant is checked too (defence in depth over
 * SchoolScope, which already makes another school's slot a 404).
 */
class TimetableSlotPolicy
{
    /**
     * Listing is always of the caller's own slots (the controller filters by
     * teacher_id); only teachers have a timetable.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Teacher->value);
    }

    public function view(User $user, TimetableSlot $slot): bool
    {
        return $this->isOwner($user, $slot);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Teacher->value);
    }

    public function update(User $user, TimetableSlot $slot): bool
    {
        return $this->isOwner($user, $slot);
    }

    public function delete(User $user, TimetableSlot $slot): bool
    {
        return $this->isOwner($user, $slot);
    }

    protected function isOwner(User $user, TimetableSlot $slot): bool
    {
        return $user->school_id === $slot->school_id
            && $slot->teacher_id === $user->id;
    }
}
