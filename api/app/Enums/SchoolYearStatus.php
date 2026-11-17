<?php

namespace App\Enums;

/**
 * A school year is open (loading as usual) or closed (its groups are history
 * and read-only, screen 26). Spanish labels live in the frontend.
 */
enum SchoolYearStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
