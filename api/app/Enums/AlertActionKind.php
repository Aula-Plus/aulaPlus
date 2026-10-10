<?php

namespace App\Enums;

/**
 * What one step of an alert's thread records (ClickUp 86e3jpzdp).
 *
 * - Outcome: someone chose one of the three ways out, with a responsible
 *   person and a deadline.
 * - Share: the alert was shared with a co-responsible, who follows it
 *   together with whoever has it — the responsible person and the deadline
 *   don't change.
 * - Note: someone in charge left an operational note (what was done with the
 *   case, never a diagnosis).
 */
enum AlertActionKind: string
{
    case Outcome = 'outcome';
    case Share = 'share';
    case Note = 'note';
}
