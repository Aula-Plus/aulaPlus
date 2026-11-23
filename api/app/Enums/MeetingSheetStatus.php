<?php

namespace App\Enums;

/**
 * A sheet is a draft (its fragments are only a proposal of how to split it,
 * written by hand for now) until psychopedagogy confirms the split; only then
 * do the fragments land in the students' files (P10-DEC-06). An AI proposal
 * step, when added, fills the draft's fragments and keeps this same gate.
 */
enum MeetingSheetStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
