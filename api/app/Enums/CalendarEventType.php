<?php

namespace App\Enums;

/**
 * The kinds of date that are stored as a CalendarEvent row (screen 21).
 *
 * - `institutional`: created by Direction in the Calendar (P21-DEC-02/05);
 *   every staff member of the school may see it.
 * - `personal`: an event of a person's own calendar (ClickUp 1dfd3mc56kp,
 *   P21-DEC-108). Any staff member creates it and may invite any active user
 *   of the school; it is stored once and appears in the calendar of each
 *   participant (calendar_calendar_event pivot). Only its creator edits or
 *   deletes it; each invitee may remove it from their own calendar.
 * - `meeting`: a meeting of psychopedagogy's agenda (screen 10), created and
 *   kept in step by App\Services\Calendar\MeetingEvents. Same
 *   participants mechanism as `personal` (App\Services\Calendar\EventParticipants);
 *   seen only by the people linked to it through their own calendar (screen
 *   21 red line).
 */
enum CalendarEventType: string
{
    case Institutional = 'institutional';
    case Personal = 'personal';
    case Meeting = 'meeting';
}
