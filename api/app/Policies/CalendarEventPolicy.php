<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CalendarEvent;
use App\Models\User;

/**
 * Who sees and manages a stored Calendar date (screen 21).
 *
 * - Institutional events: every staff member of the school sees them; only
 *   Direction creates, edits and removes them (P21-DEC-05).
 * - Any other type (meetings, reserved for screen 10): seen and managed only
 *   by the people whose own calendar it is linked to (calendar_calendar_event
 *   pivot) — the red line of screen 21: a meeting with a treating team exists
 *   only for its invitees.
 *
 * Tenant is always checked first (defence in depth over SchoolScope).
 */
class CalendarEventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CalendarEvent $calendarEvent): bool
    {
        if (! $this->sharesSchool($user, $calendarEvent)) {
            return false;
        }

        return $calendarEvent->isInstitutional() || $this->isOnOwnCalendar($user, $calendarEvent);
    }

    /**
     * The only date created in the Calendar is the institutional event, and
     * only Direction creates it.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(Role::Director->value);
    }

    public function update(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->manages($user, $calendarEvent);
    }

    public function delete(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->manages($user, $calendarEvent);
    }

    protected function manages(User $user, CalendarEvent $calendarEvent): bool
    {
        if (! $this->sharesSchool($user, $calendarEvent)) {
            return false;
        }

        if ($calendarEvent->isInstitutional()) {
            return $user->hasRole(Role::Director->value);
        }

        return $this->isOnOwnCalendar($user, $calendarEvent);
    }

    protected function sharesSchool(User $user, CalendarEvent $calendarEvent): bool
    {
        return $user->school_id === $calendarEvent->school_id;
    }

    protected function isOnOwnCalendar(User $user, CalendarEvent $calendarEvent): bool
    {
        return $calendarEvent->calendars()
            ->whereHas('user', fn ($query) => $query->whereKey($user->id))
            ->exists();
    }
}
