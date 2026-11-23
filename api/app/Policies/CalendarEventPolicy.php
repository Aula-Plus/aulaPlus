<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CalendarEvent;
use App\Models\User;

/**
 * Who sees and manages a stored Calendar date (screen 21).
 *
 * - Institutional events: every staff member of the school may see them (the
 *   school's full calendar); only Direction creates, edits and removes them
 *   (P21-DEC-05). Who an event concerns (its audience, ClickUp 1dfd3mc55zp)
 *   only decides what each person's own calendar shows by default
 *   (EventAudience) — it never hides an event from anyone who looks for it.
 * - Any other type (personal events, screen 10 meetings): seen and managed only
 *   by the people whose own calendar it is linked to (calendar_calendar_event
 *   pivot) — the red line of screen 21: a meeting with a treating team exists
 *   only for its invitees.
 * - Personal events (ClickUp 1dfd3mc56kp) and meetings: seen by everyone
 *   whose calendar holds them; edited and deleted only by their creator;
 *   each invitee may remove one from their own calendar (`leave`). Being
 *   invited never widens access to a student the event names.
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

    /**
     * An event of one's own calendar (ClickUp 1dfd3mc56kp, P21-DEC-108):
     * every role has its own calendar and may add events to it.
     */
    public function createPersonal(User $user): bool
    {
        return $user->hasAnyRole(Role::values());
    }

    /**
     * «Quitar de mi calendario»: an invitee drops a shared event from their
     * own calendar without deleting it for the others. Its creator deletes
     * it instead; institutional events are not linked to anyone's calendar.
     */
    public function leave(User $user, CalendarEvent $calendarEvent): bool
    {
        return $this->sharesSchool($user, $calendarEvent)
            && ! $calendarEvent->isInstitutional()
            && $calendarEvent->created_by_id !== $user->id
            && $this->isOnOwnCalendar($user, $calendarEvent);
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

        // A personal event or a meeting is edited or deleted only by whoever
        // created it (ClickUp 1dfd3mc56kp); invitees may only leave it.
        return $calendarEvent->created_by_id !== null
            && $calendarEvent->created_by_id === $user->id;
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
