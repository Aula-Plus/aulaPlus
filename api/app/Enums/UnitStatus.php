<?php

namespace App\Enums;

/**
 * A unit's status is derived, never chosen by hand (screen 12, P12-DEC-03):
 * `Planned` when every objective has a class or an assessment built for it,
 * `Given` when every objective was given. A unit with no objectives yet is
 * `Unplanned`.
 */
enum UnitStatus: string
{
    case Unplanned = 'unplanned';
    case Planned = 'planned';
    case Given = 'given';
}
