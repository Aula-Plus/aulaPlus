<?php

namespace App\Enums;

/**
 * The kinds of date that are stored as a CalendarEvent row (screen 21).
 *
 * - `institutional`: the only date born in the Calendar (P21-DEC-02/05).
 *   Direction creates it and every staff member of the school sees it.
 * - `meeting`: reserved for screen 10 (meetings with a treating team). Not
 *   created by any endpoint yet; when it is, it is seen only by the people
 *   linked to it through their own calendar (screen 21 red line).
 */
enum CalendarEventType: string
{
    case Institutional = 'institutional';
    case Meeting = 'meeting';
}
