<?php

namespace App\Enums;

/**
 * `PlanningAttendance` is a placeholder for when real attendance data exists
 * (docs/prompts/04-seguimiento-institucional.md §4) — the enum case exists,
 * but `alerts:generate` does not produce it yet.
 */
enum AlertType: string
{
    case Performance = 'performance';
    case Behavior = 'behavior';
    case PlanningAttendance = 'planning_attendance';
}
