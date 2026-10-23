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

    /**
     * "Alerta escalada con plazo vencido" (ClickUp 86e3jpzdp) — LEGACY: it was
     * generated when a handed-off alert's deadline passed without a way out.
     * Since the product review of 14/10 a missed deadline marks the same
     * alert instead (`alerts.overdue_at`, `alerts:overdue`), so none is
     * created any more; existing ones stay and close when the alert they
     * escalate moves.
     */
    case EscalatedOverdue = 'escalated_overdue';
}
