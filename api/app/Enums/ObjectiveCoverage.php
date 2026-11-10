<?php

namespace App\Enums;

/**
 * What the teacher records for an objective when closing a class: it was
 * covered entirely, in part, or not at all (P12-DEC-03). Only `Full` makes the
 * objective count as given.
 */
enum ObjectiveCoverage: string
{
    case Full = 'full';
    case Partial = 'partial';
    case None = 'none';
}
