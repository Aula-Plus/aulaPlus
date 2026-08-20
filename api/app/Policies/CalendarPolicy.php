<?php

namespace App\Policies;

use App\Models\Calendar;
use App\Models\User;

/**
 * A user's own calendar (1:1, see App\Models\Calendar). doc02 §2: "CRUD
 * propio — cualquier usuario sobre su propio calendario". No role check:
 * every staff member manages their own calendar regardless of role.
 */
class CalendarPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Calendar $calendar): bool
    {
        return $this->isOwner($user, $calendar);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Calendar $calendar): bool
    {
        return $this->isOwner($user, $calendar);
    }

    public function delete(User $user, Calendar $calendar): bool
    {
        return $this->isOwner($user, $calendar);
    }

    protected function isOwner(User $user, Calendar $calendar): bool
    {
        return $user->school_id === $calendar->school_id
            && $calendar->user_id === $user->id;
    }
}
