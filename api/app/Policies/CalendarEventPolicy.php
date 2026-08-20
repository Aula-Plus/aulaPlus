<?php

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\User;

/**
 * A CalendarEvent is a shared record linked to one or more calendars via the
 * calendar_calendar_event pivot. doc02 §2 groups it with Calendar under
 * "CRUD propio — cualquier usuario sobre su propio calendario": a user may
 * act on an event only if it is linked to their own calendar.
 */
class CalendarEventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->isOnOwnCalendar($user, $calendarEvent);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->isOnOwnCalendar($user, $calendarEvent);
    }

    public function delete(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->isOnOwnCalendar($user, $calendarEvent);
    }

    protected function isOnOwnCalendar(User $user, CalendarEvent $calendarEvent): bool
    {
        if ($user->school_id !== $calendarEvent->school_id) {
            return false;
        }

        return $calendarEvent->calendars()
            ->whereHas('user', fn ($query) => $query->whereKey($user->id))
            ->exists();
    }
}
