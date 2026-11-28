<?php

namespace App\Enums;

/**
 * The seven kinds of date the Calendar orders in time (screen 21,
 * P21-DEC-06). The type filter always offers all seven. `assessment`,
 * `class`, `task` and `institutional` read their own records; `meeting` holds
 * the events of people's own calendars — personal/shared events (ClickUp
 * 1dfd3mc56kp; filed under «Reunión» like the prototype's «Evento personal»)
 * and, later, screen 10's meetings. `period_closing` and `project_stage`
 * return no items until screens 26 and 18 feed them (ClickUp 1dfd3mc4bgh).
 */
enum CalendarItemType: string
{
    case Assessment = 'assessment';
    case ClassSession = 'class';
    case Meeting = 'meeting';
    case Task = 'task';
    case PeriodClosing = 'period_closing';
    case ProjectStage = 'project_stage';
    case Institutional = 'institutional';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
