<?php

namespace App\Enums;

/**
 * What one step of an alert's thread records (ClickUp 86e3jpzdp).
 *
 * - Outcome: someone chose one of the three ways out, with a responsible
 *   person and a deadline.
 * - Note: whoever has the alert left an operational note (what was done with
 *   the case, never a diagnosis).
 * - Overdue: the deadline arrived and the alert was still pending —
 *   «Llegó a su fecha sin salida». A step of the circuit, written by
 *   `alerts:overdue` (no actor); it says nothing about the student.
 */
enum AlertActionKind: string
{
    case Outcome = 'outcome';
    case Note = 'note';
    case Overdue = 'overdue';
}
