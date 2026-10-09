<?php

namespace App\Enums;

/**
 * `PlanningAttendance` is a placeholder for when real attendance data exists
 * (docs/prompts/04-seguimiento-institucional.md §4) — the enum case exists,
 * but nothing produces it yet. Comments never open alerts on their own.
 */
enum AlertType: string
{
    case Performance = 'performance';
    case PlanningAttendance = 'planning_attendance';
}
