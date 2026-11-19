<?php

namespace App\Enums;

/**
 * What the teacher decided on one section of a class proposal before
 * confirming it (screen 15, ClickUp 86e3jujh0). Approved and edited sections
 * are saved with the class; a rejected one is not saved at all. Regenerating
 * a section is a new generation request, not a decision.
 */
enum ClassSectionDecision: string
{
    case Approved = 'approved';
    case Edited = 'edited';
    case Rejected = 'rejected';
}
