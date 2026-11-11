<?php

namespace App\Enums;

/**
 * The seven kinds of date the Calendar orders in time (screen 21,
 * P21-DEC-06). The type filter always offers all seven. Only `assessment`,
 * `class`, `task` and `institutional` have a data source today; `meeting`,
 * `period_closing` and `project_stage` return no items until screens 10, 26
 * and 18 exist (ClickUp 1dfd3mc4bgh).
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
